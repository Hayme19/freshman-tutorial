"""
Enrollments: POST /enrollments  — student
"""
from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid

router = APIRouter(prefix="/enrollments", tags=["enrollments"])


@router.post("")
async def enroll(request: Request):
    payload    = require_roles(request, ["student"])
    body       = await request.json()
    course_oid = to_oid(body.get("courseId", ""))
    student_oid = to_oid(payload["sub"])

    db = get_db()
    if not db.courses.find_one({"_id": course_oid, "isPublished": True}):
        return err("Course not found or not published", 404)

    if db.enrollments.find_one({"studentId": student_oid, "courseId": course_oid}):
        return err("Already enrolled", 409)

    doc = {
        "studentId":        student_oid,
        "courseId":         course_oid,
        "enrolledAt":       datetime.now(timezone.utc),
        "completedLessons": [],
        "progressPercent":  0,
        "completedAt":      None,
    }
    result  = db.enrollments.insert_one(doc)
    doc["_id"] = result.inserted_id
    return ok(doc, "Enrolled successfully", 201)
