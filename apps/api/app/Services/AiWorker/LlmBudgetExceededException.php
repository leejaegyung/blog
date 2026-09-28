<?php

namespace App\Services\AiWorker;

/** 최근 1시간 AI 호출이 한도를 넘었다(반복 실행 같은 사고를 막는 안전장치) */
class LlmBudgetExceededException extends LlmStopException {}
