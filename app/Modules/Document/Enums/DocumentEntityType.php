<?php

namespace App\Modules\Document\Enums;

enum DocumentEntityType: int
{
    case Task = 1;
    case Comment = 2;
    case StageOutput = 3;
    case HelpArticle = 4;
}
