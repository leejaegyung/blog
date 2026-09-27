<?php

namespace App\Enums;

enum ParseStatus: string
{
    case Pending = 'pending';
    // 네이버 글: 서버가 가져오지 않으므로 사용자가 본문을 붙여넣어야 한다.
    case NeedsText = 'needs_text';
    case Parsed = 'parsed';
    // 같은 프로젝트에 내용이 같은 참고자료가 이미 있다. 분석에서 제외한다.
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
