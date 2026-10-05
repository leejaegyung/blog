from fastapi import APIRouter
from pydantic import BaseModel, Field

from app.analyzers.place_names import place_candidates

router = APIRouter(prefix="/places")


class CandidatesRequest(BaseModel):
    texts: list[str] = Field(max_length=40)


class CandidatesResponse(BaseModel):
    candidates: list[str]


@router.post("/candidates")
def candidates(body: CandidatesRequest) -> CandidatesResponse:
    """알려줄 내용 속 장소 이름 후보(LLM 없이 형태소 분석으로만)"""
    return CandidatesResponse(candidates=place_candidates([t[:2000] for t in body.texts]))
