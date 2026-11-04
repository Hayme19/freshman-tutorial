from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/submissions", tags=["submissions"])


@router.post("")
async def create_submission(request: Request):
    payload     = require_roles(request, ["student"])
    body        = await request.json()
    exercise_oid = to_oid(body.get("exerciseId", ""))
    student_oid  = to_oid(payload["sub"])
    code         = body.get("code", "")

    if not code:
        return err("code is required", 422)

    db = get_db()
    if not db.exercises.find_one({"_id": exercise_oid}):
        return err("Exercise not found", 404)

    doc = {
        "exerciseId":  exercise_oid,
        "studentId":   student_oid,
        "code":        code,
        "status":      "pending",
        "grade":       0,
        "feedback":    "",
        "submittedAt": datetime.now(timezone.utc),
    }
    result  = db.submissions.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(serial(doc), "Submission received", 201)


@router.put("/{submission_id}/grade")
async def grade_submission(submission_id: str, request: Request):
    require_roles(request, ["instructor", "tutor", "admin"])
    oid        = to_oid(submission_id)
    db         = get_db()
    submission = db.submissions.find_one({"_id": oid})
    if not submission:
        return err("Submission not found", 404)

    body    = await request.json()
    set_doc = {"status": "reviewed"}
    if "grade" in body:
        grade = int(body["grade"])
        if not 0 <= grade <= 100:
            return err("grade must be 0–100", 422)
        set_doc["grade"] = grade
    if "feedback" in body:
        set_doc["feedback"] = str(body["feedback"])

    db.submissions.update_one({"_id": oid}, {"$set": set_doc})
    return ok(db.submissions.find_one({"_id": oid}), "Submission graded")
