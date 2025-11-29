# import pymysql
# from contextlib import contextmanager

# DB_CONFIG = {
#     "host": "localhost",
#     "user": "root",
#     "password": "Akshat0#58$",
#     "database": "portfolio_db_2",
#     "cursorclass": pymysql.cursors.DictCursor,
# }

# @contextmanager
# def get_conn():
#     conn = pymysql.connect(**DB_CONFIG)
#     try:
#         yield conn
#     finally:
#         conn.close()

# database_helper.py
import mysql.connector

def get_connection():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="password",
        database="portfolio_db_2",
    )
