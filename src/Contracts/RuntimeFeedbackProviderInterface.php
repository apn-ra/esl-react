<?php declare(strict_types=1);

namespace Apntalk\EslReact\Contracts;

use Apntalk\EslReact\Runner\RuntimeFeedbackSnapshot;

interface RuntimeFeedbackProviderInterface
{
    public function feedbackSnapshot(): RuntimeFeedbackSnapshot;
}
