from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Dict, Any, List
from datetime import datetime, timedelta
import pandas as pd
import requests
from requests.exceptions import ReadTimeout, RequestException
from io import StringIO

from database_helper import get_connection  # your existing helper

app = FastAPI(title="PMS Rebalance API")


# ---------- MODELS ----------

class RebalanceRequest(BaseModel):
    portfolio_id: int
    user_id: int
    no_of_stocks: int


# ---------- CORE HELPERS ----------

def check_30d_rule(user_id: int, portfolio_id: int):
    conn = get_connection()
    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT last_rebalanced_at
        FROM user_portfolios
        WHERE user_id = %s AND portfolio_id = %s
        """,
        (user_id, portfolio_id),
    )
    row = cur.fetchone()
    cur.close()
    conn.close()

    if not row:
        raise HTTPException(status_code=400, detail="User is not subscribed to this portfolio.")

    last = row["last_rebalanced_at"]
    if last is None:
        return
    if datetime.utcnow() - last < timedelta(days=30):
        raise HTTPException(
            status_code=400,
            detail="Portfolio can be updated only after 30 days since last rebalance.",
        )


def get_index_csv_for_portfolio(portfolio_id: int) -> pd.DataFrame:
    conn = get_connection()
    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT p.index_symbol, i.url
        FROM portfolios p
        JOIN indices i ON p.index_symbol = i.symbol
        WHERE p.portfolio_id = %s
        """,
        (portfolio_id,),
    )
    row = cur.fetchone()
    cur.close()
    conn.close()

    if not row:
        raise HTTPException(status_code=400, detail="Index info not found for this portfolio.")

    index_symbol = row["index_symbol"]
    url = row["url"]

    try:
        r = requests.get(url, timeout=20)
        r.raise_for_status()
    except ReadTimeout:
        raise HTTPException(
            status_code=503,
            detail=f"Timed out while fetching index data for {index_symbol}. Please try again later."
        )
    except RequestException as e:
        raise HTTPException(
            status_code=502,
            detail=f"Error fetching index data for {index_symbol}: {e}"
        )

    df = pd.read_csv(StringIO(r.text))
    return df


def load_holdings_df(portfolio_id: int) -> pd.DataFrame:
    conn = get_connection()
    cur = conn.cursor(dictionary=True)
    cur.execute(
        """
        SELECT
            symbol AS SYMBOL,
            current_price AS LTP,
            quantity AS QUANTITY,
            invested_amount AS `INVESTED AMOUNT`,
            date_of_purchase AS `DATE OF PURCHASE`
        FROM portfolio_holdings
        WHERE portfolio_id = %s
        """,
        (portfolio_id,),
    )
    rows = cur.fetchall()
    cur.close()
    conn.close()

    if not rows:
        return pd.DataFrame(
            columns=["SYMBOL", "LTP", "QUANTITY", "INVESTED AMOUNT", "DATE OF PURCHASE"]
        )
    return pd.DataFrame(rows)


def get_free_cash_and_total(portfolio_id: int, user_id: int):
    conn = get_connection()
    cur = conn.cursor(dictionary=True)

    cur.execute(
        """
        SELECT total_invested
        FROM user_portfolios
        WHERE user_id = %s AND portfolio_id = %s
        """,
        (user_id, portfolio_id),
    )
    row = cur.fetchone()
    if not row:
        cur.close()
        conn.close()
        raise HTTPException(status_code=400, detail="User-portfolio link not found.")
    total_capital = float(row["total_invested"])

    cur.execute(
        """
        SELECT COALESCE(SUM(invested_amount), 0) AS invested_sum
        FROM portfolio_holdings
        WHERE portfolio_id = %s
        """,
        (portfolio_id,),
    )
    row2 = cur.fetchone()
    invested_sum = float(row2["invested_sum"] or 0.0)

    cur.close()
    conn.close()

    free_cash = total_capital - invested_sum
    return free_cash, total_capital


def run_update_portfolio_logic(
    df_index: pd.DataFrame,
    portfolio_df: pd.DataFrame,
    no_of_stocks: int,
    free_cash: float
) -> Dict[str, Any]:
    df = df_index.copy()
    df.columns = (
        df.columns.astype(str)
        .str.strip()
        .str.replace("\n", "")
        .str.replace("  ", " ", regex=False)
    )

    # some files have header row duplicated; drop if needed
    if len(df) > 0 and str(df.iloc[0].get("SYMBOL", "")).upper() == "SYMBOL":
        df = df.iloc[1:].copy()

    df["LTP"] = (
        df["LTP"].astype(str)
        .str.replace(",", "")
        .str.replace("₹", "")
        .str.strip()
    )
    df["LTP"] = pd.to_numeric(df["LTP"], errors="coerce")

    df["30 D %CHNG"] = (
        df["30 D %CHNG"].astype(str)
        .str.replace("%", "")
        .str.strip()
    )
    df["30 D %CHNG"] = pd.to_numeric(df["30 D %CHNG"], errors="coerce")

    new_top = df.sort_values(by="30 D %CHNG", ascending=False).head(no_of_stocks)

    new_portfolio: List[Dict[str, Any]] = []
    old_symbols = set(portfolio_df["SYMBOL"])
    new_symbols = set(new_top["SYMBOL"])

    common = old_symbols & new_symbols

    # keep common stocks
    for _, row in new_top.iterrows():
        if row["SYMBOL"] in common:
            old_row = portfolio_df[portfolio_df["SYMBOL"] == row["SYMBOL"]].iloc[0]
            new_portfolio.append(
                {
                    "SYMBOL": row["SYMBOL"],
                    "LTP": float(row["LTP"]),
                    "QUANTITY": int(old_row["QUANTITY"]),
                    "INVESTED AMOUNT": float(old_row["INVESTED AMOUNT"]),
                    "1M RETURN (%)": float(row["30 D %CHNG"]),
                    "DATE OF PURCHASE": old_row["DATE OF PURCHASE"],
                }
            )

    # sell removed stocks
    sold_symbols = old_symbols - new_symbols
    freed_cash = 0.0
    for sym in sold_symbols:
        r = portfolio_df[portfolio_df["SYMBOL"] == sym].iloc[0]
        freed_cash += float(r["QUANTITY"]) * float(r["LTP"])
    free_cash += freed_cash

    # buy new entries
    new_entries = new_symbols - old_symbols
    if new_entries:
        cash_per_new = free_cash // len(new_entries)
        purchase_date = datetime.today().strftime("%Y-%m-%d")
        for _, row in new_top.iterrows():
            if row["SYMBOL"] in new_entries:
                ltp = float(row["LTP"])
                if ltp > 0 and ltp <= cash_per_new:
                    qty = int(cash_per_new // ltp)
                    if qty <= 0:
                        continue
                    invested = qty * ltp
                    free_cash -= invested
                    new_portfolio.append(
                        {
                            "SYMBOL": row["SYMBOL"],
                            "LTP": ltp,
                            "QUANTITY": qty,
                            "INVESTED AMOUNT": invested,
                            "1M RETURN (%)": float(row["30 D %CHNG"]),
                            "DATE OF PURCHASE": purchase_date,
                        }
                    )

    new_df = pd.DataFrame(new_portfolio)
    return {
        "portfolio_df": new_df,
        "free_cash": free_cash,
        "sold_symbols": list(sold_symbols),
        "new_entries": list(new_entries),
        "common_stocks": list(common),
    }


# ---------- MAIN ENDPOINT ----------

@app.post("/rebalance_portfolio")
def rebalance_portfolio(req: RebalanceRequest):
    portfolio_id = req.portfolio_id
    user_id = req.user_id
    no_of_stocks = req.no_of_stocks

    check_30d_rule(user_id, portfolio_id)

    df_index = get_index_csv_for_portfolio(portfolio_id)
    old_holdings = load_holdings_df(portfolio_id)
    free_cash, _ = get_free_cash_and_total(portfolio_id, user_id)

    if old_holdings.empty:
        raise HTTPException(status_code=400, detail="No holdings to rebalance.")

    res = run_update_portfolio_logic(df_index, old_holdings, no_of_stocks, free_cash)
    new_df = res["portfolio_df"]
    new_free_cash = res["free_cash"]

    conn = get_connection()
    cur = conn.cursor(dictionary=True)

    try:
        # clear holdings
        cur.execute("DELETE FROM portfolio_holdings WHERE portfolio_id = %s", (portfolio_id,))

        # insert new holdings
        insert_sql = """
            INSERT INTO portfolio_holdings
            (portfolio_id, symbol, company_name, date_of_purchase,
             buy_price, current_price, quantity,
             invested_amount, current_value, pl_amount, pl_percent)
            VALUES (%s, %s, NULL, %s, %s, %s, %s, %s, %s, 0, 0)
        """
        total_invested = 0.0
        for _, row in new_df.iterrows():
            sym = row["SYMBOL"]
            ltp = float(row["LTP"])
            qty = int(row["QUANTITY"])
            inv = float(row["INVESTED AMOUNT"])
            total_invested += inv
            cur.execute(
                insert_sql,
                (portfolio_id, sym, row["DATE OF_PURCHASE"] if "DATE_OF_PURCHASE" not in row else row["DATE_OF_PURCHASE"], ltp, ltp, qty, inv, inv),
            )

        # minimal transaction log (optional but created here)
        txn_sql = """
            INSERT INTO portfolio_transactions
            (portfolio_id, user_id, symbol, txn_type,
             quantity, price, amount,
             before_quantity, after_quantity,
             before_invested, after_invested, reason)
            VALUES (%s, %s, %s, %s,
                    %s, %s, %s,
                    %s, %s,
                    %s, %s, %s)
        """

        old_map = {r["SYMBOL"]: r for _, r in old_holdings.iterrows()}
        new_map = {r["SYMBOL"]: r for _, r in new_df.iterrows()}
        reason = "30D rebalance"

        # sells
        for sym in res["sold_symbols"]:
            o = old_map[sym]
            bq = int(o["QUANTITY"])
            bi = float(o["INVESTED AMOUNT"])
            price = float(o["LTP"])
            amt = bq * price
            cur.execute(
                txn_sql,
                (portfolio_id, user_id, sym, "SELL",
                 bq, price, amt,
                 bq, 0,
                 bi, 0, reason),
            )

        # buys
        for sym in res["new_entries"]:
            n = new_map[sym]
            aq = int(n["QUANTITY"])
            ai = float(n["INVESTED AMOUNT"])
            price = float(n["LTP"])
            amt = aq * price
            cur.execute(
                txn_sql,
                (portfolio_id, user_id, sym, "BUY",
                 aq, price, amt,
                 0, aq,
                 0, ai, reason),
            )

        # update user_portfolios
        cur.execute(
            """
            UPDATE user_portfolios
            SET last_rebalanced_at = %s,
                total_invested     = %s
            WHERE user_id = %s AND portfolio_id = %s
            """,
            (datetime.utcnow(), total_invested, user_id, portfolio_id),
        )

        conn.commit()
    except Exception as e:
        conn.rollback()
        cur.close()
        conn.close()
        raise HTTPException(status_code=500, detail=str(e))

    cur.close()
    conn.close()

    return {
        "success": True,
        "portfolio_id": portfolio_id,
        "total_invested": total_invested,
        "free_cash": new_free_cash,
    }
