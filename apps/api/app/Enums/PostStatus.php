<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Planning = 'planning';
    case Planned = 'planned';
    case Generating = 'generating';
    case Review = 'review';
    case Published = 'published';
    case Failed = 'failed';
}
