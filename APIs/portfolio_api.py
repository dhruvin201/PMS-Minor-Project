from fastapi import FastAPI, HTTPException
from pydantic import BaseModel
from typing import Dict, Any
import os
import time
import urllib.parse
from datetime import datetime

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.chrome.service import Service
from selenium.webdriver.chrome.options import Options
from webdriver_manager.chrome import ChromeDriverManager

import pandas as pd

app = FastAPI()

DOWNLOAD_DIR = os.path.abspath("nse_indices_downloads_api")
os.makedirs(DOWNLOAD_DIR, exist_ok=True)

# ---------- Chrome driver (reuse across requests) ----------
driver: webdriver.Chrome | None = None

def create_chrome_options():
    chrome_options = Options()
    chrome_options.add_argument("--disable-blink-features=AutomationControlled")
    chrome_options.add_argument(
        "user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
        "AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36"
    )
    prefs = {
        "download.default_directory": DOWNLOAD_DIR,
        "download.prompt_for_download": False,
        "download.directory_upgrade": True,
        "safebrowsing.enabled": True,
    }
    chrome_options.add_experimental_option("prefs", prefs)
    chrome_options.add_argument("--headless=new")
    chrome_options.add_argument("--no-sandbox")
    chrome_options.add_argument("--disable-dev-shm-usage")
    return chrome_options

def get_driver() -> webdriver.Chrome:
    global driver
    if driver is not None:
        return driver
    driver = webdriver.Chrome(
        service=Service(ChromeDriverManager().install()),
        options=create_chrome_options(),
    )
    return driver

# ---------- Request model ----------
class IndexRequest(BaseModel):
    index_symbol: str      # e.g. "NIFTY 50"
    no_of_stocks: int      # user choice from view_scripts.php
    total_capital: float   # investment amount from Add Portfolio page

# ---------- Helper: wait for latest CSV ----------
def wait_for_latest_csv(timeout: int = 20) -> str:
    """Return the most recently modified CSV in DOWNLOAD_DIR within timeout."""
    end = time.time() + timeout
    latest_file = ""
    latest_mtime = 0.0

    while time.time() < end:
        for f in os.listdir(DOWNLOAD_DIR):
            if not f.lower().endswith(".csv"):
                continue
            full = os.path.join(DOWNLOAD_DIR, f)
            mtime = os.path.getmtime(full)
            if mtime > latest_mtime:
                latest_mtime = mtime
                latest_file = full
        if latest_file:
            return latest_file
        time.sleep(0.3)
    return ""

# ---------- Portfolio construction logic ----------
def initialize_portfolio_from_index(
    df: pd.DataFrame, total_capital: float, no_of_stocks: int
) -> Dict[str, Any]:
    """
    Strategy:
    1) Pick top N by 365 D % CHNG.
    2) Pass 1: equal capital per stock, buy floor(investment_per_stock / LTP).
    3) Pass 2: for stocks with QUANTITY == 0, try to buy 1 share if free_cash >= LTP.
    4) Pass 3: greedy, for all N stocks in order, buy as many extra shares as
       possible with remaining free_cash to minimize leftover cash.
    """
    portfolio = []
    purchase_date = datetime.today().strftime("%Y-%m-%d")

    # Normalize columns
    df.columns = (
        df.columns.astype(str)
        .str.strip()
        .str.replace("\n", " ")
        .str.replace("  ", " ", regex=False)
    )

    # Drop duplicate header row if present
    if len(df) > 0 and str(df.iloc[0]["SYMBOL"]).upper() == "SYMBOL":
        df = df.iloc[1:].copy()

    # Clean LTP
    df["LTP"] = (
        df["LTP"]
        .astype(str)
        .str.replace(",", "")
        .str.replace("₹", "")
        .str.strip()
    )
    df["LTP"] = pd.to_numeric(df["LTP"], errors="coerce")

    # 365‑day change column
    change_col = "365 D % CHNG"
    if change_col not in df.columns:
        raise RuntimeError(f"Expected column '{change_col}' not found in CSV")

    df[change_col] = (
        df[change_col]
        .astype(str)
        .str.replace("%", "")
        .str.strip()
    )
    df[change_col] = pd.to_numeric(df[change_col], errors="coerce")

    # Rank by 1‑year return and pick top N
    df = df.sort_values(by=change_col, ascending=False).head(no_of_stocks).copy()

    # Filter out rows without valid price
    df = df[pd.notna(df["LTP"]) & (df["LTP"] > 0)].copy()
    if df.empty:
        raise RuntimeError("No valid prices found to build portfolio")

    investment_per_stock = total_capital // no_of_stocks

    # ---------- PASS 1: equal-budget buy ----------
    for _, row in df.iterrows():
        ltp = float(row["LTP"])
        qty = int(investment_per_stock // ltp)
        invested = qty * ltp

        portfolio.append(
            {
                "SYMBOL": row["SYMBOL"],
                "LTP": round(ltp, 2),
                "QUANTITY": qty,
                "INVESTED_AMOUNT": round(invested, 2),
                "ONE_YEAR_RETURN_PCT": round(
                    float(row[change_col]) if pd.notna(row[change_col]) else 0.0, 2
                ),
                "DATE_OF_PURCHASE": purchase_date,
            }
        )

    portfolio_df = pd.DataFrame(portfolio)

    # Ensure all selected symbols exist (if some were missing)
    selected_symbols = set(df["SYMBOL"])
    existing_symbols = set(portfolio_df["SYMBOL"])
    missing = selected_symbols - existing_symbols
    for sym in missing:
        row = df[df["SYMBOL"] == sym].iloc[0]
        portfolio_df = pd.concat(
            [
                portfolio_df,
                pd.DataFrame(
                    [
                        {
                            "SYMBOL": sym,
                            "LTP": round(float(row["LTP"]), 2),
                            "QUANTITY": 0,
                            "INVESTED_AMOUNT": 0.0,
                            "ONE_YEAR_RETURN_PCT": round(
                                float(row[change_col]) if pd.notna(row[change_col]) else 0.0,
                                2,
                            ),
                            "DATE_OF_PURCHASE": purchase_date,
                        }
                    ]
                ),
            ],
            ignore_index=True,
        )

    # Free cash after pass 1
    total_invested = float(portfolio_df["INVESTED_AMOUNT"].sum())
    free_cash = float(total_capital - total_invested)

    # Ranked view (top N) for further passes
    df_ranked = df.sort_values(by=change_col, ascending=False).reset_index(drop=True)
    ltp_map = {row["SYMBOL"]: float(row["LTP"]) for _, row in df_ranked.iterrows()}

    # Quick symbol index for faster updates
    symbol_index = {sym: i for i, sym in enumerate(portfolio_df["SYMBOL"])}

    # ---------- PASS 2: ensure each stock gets at least 1 share if possible ----------
    for _, row in df_ranked.iterrows():
        sym = row["SYMBOL"]
        ltp = ltp_map[sym]
        if ltp <= 0:
            continue

        i = symbol_index[sym]
        current_qty = int(portfolio_df.at[i, "QUANTITY"])

        if current_qty == 0 and free_cash >= ltp:
            portfolio_df.at[i, "QUANTITY"] += 1
            portfolio_df.at[i, "INVESTED_AMOUNT"] += ltp
            free_cash -= ltp

    # ---------- PASS 3: greedy allocation across all N stocks ----------
    while True:
        bought_any = False

        for _, row in df_ranked.iterrows():
            sym = row["SYMBOL"]
            ltp = ltp_map[sym]
            if ltp <= 0 or free_cash < ltp:
                continue

            extra_qty = int(free_cash // ltp)
            if extra_qty <= 0:
                continue

            i = symbol_index[sym]
            portfolio_df.at[i, "QUANTITY"] += extra_qty
            portfolio_df.at[i, "INVESTED_AMOUNT"] += extra_qty * ltp
            free_cash -= extra_qty * ltp
            bought_any = True

            if free_cash <= 0:
                break

        if not bought_any:
            break

    portfolio_df["INVESTED_AMOUNT"] = portfolio_df["INVESTED_AMOUNT"].round(2)

    portfolio_df = portfolio_df.sort_values(
        by="ONE_YEAR_RETURN_PCT", ascending=False
    ).reset_index(drop=True)

    return {
        "portfolio": portfolio_df.to_dict(orient="records"),
        "total_invested": float(portfolio_df["INVESTED_AMOUNT"].sum()),
        "free_cash": float(free_cash),
    }

# ---------- Endpoint ----------
@app.post("/scrape_index_csv")
async def scrape_index_csv(req: IndexRequest) -> Dict:
    index_symbol = req.index_symbol.strip()
    no_of_stocks = req.no_of_stocks
    total_capital = req.total_capital

    if not index_symbol:
        raise HTTPException(status_code=400, detail="index_symbol is required")
    if no_of_stocks <= 0:
        raise HTTPException(status_code=400, detail="no_of_stocks must be > 0")
    if total_capital <= 0:
        raise HTTPException(status_code=400, detail="total_capital must be > 0")

    base = "https://www.nseindia.com/market-data/live-equity-market"
    query = urllib.parse.urlencode({"symbol": index_symbol})
    url = f"{base}?{query}"

    try:
        d = get_driver()

        # Clear old CSVs so the next one is definitely from this request
        for f in os.listdir(DOWNLOAD_DIR):
            if f.lower().endswith(".csv"):
                try:
                    os.remove(os.path.join(DOWNLOAD_DIR, f))
                except OSError:
                    pass

        d.get(url)
        time.sleep(3)

        download_link = d.find_element(By.ID, "dnldEquityStock")
        download_link.click()

        csv_path = wait_for_latest_csv(timeout=20)
        if not csv_path:
            raise HTTPException(status_code=500, detail="CSV download not found")

        df = pd.read_csv(csv_path)

        portfolio_result = initialize_portfolio_from_index(
            df, total_capital=total_capital, no_of_stocks=no_of_stocks
        )

        return {
            "success": True,
            "index_symbol": index_symbol,
            "no_of_stocks": no_of_stocks,
            "total_capital": total_capital,
            **portfolio_result,
        }

    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))
