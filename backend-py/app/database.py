"""
MongoDB connection singleton.
"""
import os
from pymongo import MongoClient
from pymongo.database import Database
from dotenv import load_dotenv

load_dotenv()

_client: MongoClient | None = None
_db: Database | None = None


def get_db() -> Database:
    global _client, _db
    if _db is None:
        uri  = os.getenv("MONGODB_URI", "")
        name = os.getenv("DB_NAME", "tutorialdb")
        if not uri:
            raise RuntimeError("MONGODB_URI is not set in .env")
        _client = MongoClient(uri)
        _db     = _client[name]
    return _db
