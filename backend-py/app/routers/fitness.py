from datetime import datetime, timezone, date
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/fitness-logs", tags=["fitness"])


@router.get("")
async def list_logs(request: Request):
    payload = require_roles(request, ["any"])
    db      = get_db()
    logs    = list(db.fitnessLogs.find({"userId": to_oid(payload["sub"])}).sort("logDate", -1))
    return ok(logs)


@router.post("")
async def create_log(request: Request):
    payload  = require_roles(request, ["any"])
    body     = await request.json()
    activity = body.get("activity", "").strip()
    if not activity:
        return err("activity is required", 422)

    db  = get_db()
    doc = {
        "userId":          to_oid(payload["sub"]),
        "activity":        activity,
        "durationMinutes": int(body.get("durationMinutes", 0)),
        "notes":           str(body.get("notes", "")),
        "logDate":         str(body.get("logDate", date.today().isoformat())),
        "createdAt":       datetime.now(timezone.utc),
    }
    result  = db.fitnessLogs.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(serial(doc), "Log created", 201)


@router.put("/{log_id}")
async def update_log(log_id: str, request: Request):
    payload = require_roles(request, ["any"])
    oid     = to_oid(log_id)
    db      = get_db()
    log     = db.fitnessLogs.find_one({"_id": oid})
    if not log:
        return err("Log not found", 404)
    if str(log["userId"]) != payload["sub"]:
        return err("Forbidden", 403)

    body    = await request.json()
    allowed = {"activity", "durationMinutes", "notes", "logDate"}
    set_doc = {}
    for f in allowed:
        if f in body:
            set_doc[f] = int(body[f]) if f == "durationMinutes" else str(body[f])
    if not set_doc:
        return err("Nothing to update", 422)

    db.fitnessLogs.update_one({"_id": oid}, {"$set": set_doc})
    return ok(db.fitnessLogs.find_one({"_id": oid}), "Log updated")


@router.delete("/{log_id}")
async def delete_log(log_id: str, request: Request):
    payload = require_roles(request, ["any"])
    oid     = to_oid(log_id)
    db      = get_db()
    log     = db.fitnessLogs.find_one({"_id": oid})
    if not log:
        return err("Log not found", 404)
    if str(log["userId"]) != payload["sub"]:
        return err("Forbidden", 403)
    db.fitnessLogs.delete_one({"_id": oid})
    return ok(None, "Log deleted")
