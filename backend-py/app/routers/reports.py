from fastapi import APIRouter, Request
from app.database import get_db
from app.utils    import ok, require_roles

router = APIRouter(prefix="/reports", tags=["reports"])


@router.get("/summary")
async def summary(request: Request):
    require_roles(request, ["admin", "instructor"])
    db = get_db()

    natural_count = db.users.count_documents({"stream": "natural", "isActive": True})
    social_count  = db.users.count_documents({"stream": "social",  "isActive": True})
    total_enroll  = db.enrollments.count_documents({})
    completed     = db.enrollments.count_documents({"completedAt": {"$ne": None}})

    # Avg quiz score
    avg_score = 0.0
    pipeline  = [{"$group": {"_id": None,
        "avg": {"$avg": {"$cond": [
            {"$gt": ["$total", 0]},
            {"$multiply": [{"$divide": ["$score", "$total"]}, 100]},
            0
        ]}}
    }}]
    for row in db.quizAttempts.aggregate(pipeline):
        avg_score = round(float(row.get("avg", 0)), 1)

    # Top 5 courses
    top_pipeline = [
        {"$group": {"_id": "$courseId", "count": {"$sum": 1}}},
        {"$sort":  {"count": -1}},
        {"$limit": 5},
    ]
    top_list = []
    for row in db.enrollments.aggregate(top_pipeline):
        course = db.courses.find_one({"_id": row["_id"]}, {"title": 1})
        top_list.append({
            "courseId":    str(row["_id"]),
            "courseTitle": course.get("title", "") if course else "",
            "count":       row["count"],
        })

    return ok({
        "activeUsers":        {"natural": natural_count, "social": social_count},
        "totalEnrollments":   total_enroll,
        "completedCourses":   completed,
        "completionRate":     round(completed / total_enroll * 100, 1) if total_enroll else 0,
        "avgQuizScorePct":    avg_score,
        "topEnrolledCourses": top_list,
    })
