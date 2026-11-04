"""
Shared utilities: response shape, ObjectId serialisation, JWT helpers.
"""
import os
from datetime import datetime, timezone, timedelta
from typing import Any

from bson        import ObjectId
from fastapi     import HTTPException, Request
from fastapi.responses import JSONResponse
from jose        import jwt, JWTError


# ── Response shape ────────────────────────────────────────────────────────────

def ok(data: Any = None, message: str = "OK", status: int = 200) -> JSONResponse:
    return JSONResponse({"success": True,  "data": serial(data), "message": message}, status_code=status)

def err(message: str, status: int = 400) -> JSONResponse:
    return JSONResponse({"success": False, "data": None,         "message": message}, status_code=status)


# ── BSON serialiser ───────────────────────────────────────────────────────────

def serial(obj: Any) -> Any:
    """Recursively convert BSON types to JSON-safe values."""
    if isinstance(obj, ObjectId):
        return {"$oid": str(obj)}
    if isinstance(obj, datetime):
        return obj.isoformat()
    if isinstance(obj, dict):
        return {k: serial(v) for k, v in obj.items()}
    if isinstance(obj, list):
        return [serial(i) for i in obj]
    return obj

def to_oid(id_str: str) -> ObjectId:
    """Cast string to ObjectId or raise HTTP 400."""
    try:
        return ObjectId(id_str)
    except Exception:
        raise HTTPException(400, "Invalid id format")


# ── JWT ───────────────────────────────────────────────────────────────────────

SECRET   = os.getenv("JWT_SECRET", "change-me")
ALGO     = "HS256"
EXPIRE_H = 24


def create_token(user_id: str, role: str, stream: str | None) -> str:
    exp = datetime.now(timezone.utc) + timedelta(hours=EXPIRE_H)
    return jwt.encode(
        {"sub": user_id, "role": role, "stream": stream, "exp": exp},
        SECRET, algorithm=ALGO
    )

def decode_token(token: str) -> dict:
    try:
        return jwt.decode(token, SECRET, algorithms=[ALGO])
    except JWTError:
        raise HTTPException(401, "Invalid or expired token")

def get_payload(request: Request) -> dict:
    """Extract and verify Bearer token from request headers."""
    auth = request.headers.get("Authorization", "")
    if not auth.startswith("Bearer "):
        raise HTTPException(401, "Unauthorized")
    return decode_token(auth[7:])

def require_roles(request: Request, roles: list[str]) -> dict:
    """Verify token and check role. Use ['any'] to allow all authenticated users."""
    payload = get_payload(request)
    if "any" not in roles and payload.get("role") not in roles:
        raise HTTPException(403, "Forbidden")
    return payload
