<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Analyzed = 'analyzed';
    case Failed = 'failed';
}
