"""
Progress: complete-lesson, get my progress
"""
from datetime import datetime, timezone
from fastapi  import APIRouter, Request
from app.database import get_db
from app.utils    import ok, err, require_roles, to_oid, serial

router = APIRouter(prefix="/progress", tags=["progress"])


@router.post("/complete-lesson")
async def complete_lesson(request: Request):
    payload    = require_roles(request, ["student"])
    body       = await request.json()
    lesson_oid = to_oid(body.get("lessonId", ""))
    student_oid = to_oid(payload["sub"])

    db     = get_db()
    lesson = db.lessons.find_one({"_id": lesson_oid})
    if not lesson:
        return err("Lesson not found", 404)

    course_oid  = lesson["courseId"]
    enrollment  = db.enrollments.find_one({"studentId": student_oid, "courseId": course_oid})
    if not enrollment:
        return err("Not enrolled in this course", 403)

    # $addToSet — ignore if already present
    db.enrollments.update_one(
        {"studentId": student_oid, "courseId": course_oid},
        {"$addToSet": {"completedLessons": lesson_oid}}
    )

    enrollment      = db.enrollments.find_one({"studentId": student_oid, "courseId": course_oid})
    total_lessons   = db.lessons.count_documents({"courseId": course_oid})
    completed_count = len(enrollment.get("completedLessons", []))
    percent         = round(completed_count / total_lessons * 100) if total_lessons else 0

    update = {"progressPercent": percent}

    if percent >= 100 and enrollment.get("completedAt") is None:
        if _final_quiz_passed(db, student_oid, course_oid):
            update["completedAt"] = datetime.now(timezone.utc)
            _create_certificate(db, student_oid, course_oid)

    db.enrollments.update_one(
        {"studentId": student_oid, "courseId": course_oid},
        {"$set": update}
    )
    return ok({"progressPercent": percent}, "Progress updated")


@router.get("/me")
async def get_my_progress(request: Request):
    payload     = require_roles(request, ["any"])
    student_oid = to_oid(payload["sub"])
    db          = get_db()

    enrollments = list(db.enrollments.find({"studentId": student_oid}))
    result      = []
    for e in enrollments:
        course      = db.courses.find_one({"_id": e["courseId"]}, {"title": 1})
        attempts    = list(db.quizAttempts.find({"studentId": student_oid}).sort("takenAt", -1))
        row         = serial(e)
        row["courseTitle"] = serial(course.get("title", "")) if course else ""
        row["quizHistory"] = serial(attempts)
        result.append(row)

    return ok(result)


# ── helpers ───────────────────────────────────────────────────────────────────

def _final_quiz_passed(db, student_oid, course_oid) -> bool:
    quizzes = list(db.quizzes.find({"courseId": course_oid}))
    if not quizzes:
        return False
    for quiz in quizzes:
        attempt = db.quizAttempts.find_one(
            {"quizId": quiz["_id"], "studentId": student_oid},
            sort=[("takenAt", -1)]
        )
        if attempt:
            total = attempt.get("total", 0)
            score = attempt.get("score", 0)
            if total > 0 and score / total >= 0.5:
                return True
    return False


def _create_certificate(db, student_oid, course_oid):
    if db.certificates.find_one({"studentId": student_oid, "courseId": course_oid}):
        return
    course = db.courses.find_one({"_id": course_oid}, {"title": 1})
    user   = db.users.find_one({"_id": student_oid}, {"fullName": 1})
    db.certificates.insert_one({
        "studentId":   student_oid,
        "courseId":    course_oid,
        "courseTitle": course.get("title", "") if course else "",
        "studentName": user.get("fullName", "") if user else "",
        "issuedAt":    datetime.now(timezone.utc),
    })
