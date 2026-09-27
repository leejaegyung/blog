<?php

namespace App\Enums;

// 기획서 31장 작성 Wizard 4단계
enum Tone: string
{
    case Natural = 'natural';
    case Expert = 'expert';
    case Friendly = 'friendly';
    case Clean = 'clean';
}
