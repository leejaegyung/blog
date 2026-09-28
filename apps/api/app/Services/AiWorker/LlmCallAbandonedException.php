<?php

namespace App\Services\AiWorker;

/** AI 호출을 보냈지만 응답을 기다리다 시간이 넘었다. AI는 이미 사용량을 썼을 수 있어 자동으로 다시 부르지 않는다 */
class LlmCallAbandonedException extends LlmStopException {}
