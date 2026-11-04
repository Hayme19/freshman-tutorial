"""
Auth routes: register, login, me
"""
from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from passlib.context import CryptContext

from app.database import get_db
from app.utils    import ok, err, create_token, require_roles, to_oid, serial

router = APIRouter(prefix="/auth", tags=["auth"])
pwd    = CryptContext(schemes=["bcrypt"], deprecated="auto")


@router.post("/register")
async def register(request: Request):
    body = await request.json()

    allowed = {"fullName", "email", "password", "stream"}
    unknown = set(body) - allowed
    if unknown:
        return err(f"Unexpected fields: {', '.join(unknown)}", 422)

    full_name = body.get("fullName", "").strip()
    email     = body.get("email", "").strip().lower()
    password  = body.get("password", "")
    stream    = body.get("stream")

    if not full_name or not email or not password:
        return err("fullName, email and password are required", 422)
    if len(password) < 8:
        return err("Password must be at least 8 characters", 422)
    if stream and stream not in ("natural", "social"):
        return err("stream must be natural or social", 422)

    db = get_db()

    if db.users.count_documents({"email": email}) > 0:
        return err("Email already registered", 409)

    doc = {
        "fullName":     full_name,
        "email":        email,
        "passwordHash": pwd.hash(password),
        "role":         "student",
        "stream":       stream,
        "avatarUrl":    "",
        "isActive":     True,
        "createdAt":    datetime.now(timezone.utc),
    }
    result = db.users.insert_one(doc)
    uid    = str(result.inserted_id)
    token  = create_token(uid, "student", stream)

    return ok({
        "token": token,
        "user":  {"_id": {"$oid": uid}, "fullName": full_name,
                  "email": email, "role": "student", "stream": stream},
    }, "Registration successful", 201)


@router.post("/login")
async def login(request: Request):
    body     = await request.json()
    email    = body.get("email", "").strip().lower()
    password = body.get("password", "")
    ip       = request.client.host if request.client else "unknown"

    if not email or not password:
        return err("email and password are required", 422)

    db = get_db()

    # Rate-limit: 5 failures / 15 min
    from datetime import timedelta
    cutoff = datetime.now(timezone.utc) - timedelta(minutes=15)
    key    = f"{email}|{ip}"
    fails  = db.loginAttempts.count_documents({
        "key": key, "createdAt": {"$gte": cutoff}
    })
    if fails >= 5:
        return err("Too many failed attempts. Try again in 15 minutes.", 429)

    user = db.users.find_one({"email": email})
    if not user or not pwd.verify(password, user.get("passwordHash", "")):
        db.loginAttempts.insert_one({
            "key": key, "email": email, "ip": ip,
            "createdAt": datetime.now(timezone.utc)
        })
        return err("Invalid email or password", 401)

    if not user.get("isActive", True):
        return err("Account is disabled", 403)

    db.loginAttempts.delete_many({"key": key})

    token  = create_token(str(user["_id"]), user.get("role", "student"), user.get("stream"))
    safe   = serial(user)
    safe.pop("passwordHash", None)

    return ok({"token": token, "user": safe}, "Login successful")


@router.get("/me")
async def me(request: Request):
    payload = require_roles(request, ["any"])
    db      = get_db()
    user    = db.users.find_one({"_id": to_oid(payload["sub"])})
    if not user:
        return err("User not found", 404)
    safe = serial(user)
    safe.pop("passwordHash", None)
    return ok(safe)
