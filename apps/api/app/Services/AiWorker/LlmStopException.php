<?php

namespace App\Services\AiWorker;

/**
 * 이 오류로 끝난 작업은 다시 시도하지 않는다(토큰 누수 방지).
 * 메시지는 화면에 그대로 보여 준다.
 */
class LlmStopException extends AiWorkerUnavailableException {}
