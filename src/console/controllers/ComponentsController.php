<?php

namespace b10k\componentguide\console\controllers;

use b10k\componentguide\Plugin;
use craft\helpers\Console;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * CLI diagnostics for Component Guide.
 *
 * `php craft component-guide/components/scan`
 *
 * Runs the same discovery the CP uses, so you can verify configuration and see
 * discovered components (and any errors) without opening the control panel.
 */
class ComponentsController extends Controller
{
    public function actionScan(): int
    {
        $repository = Plugin::getInstance()->getRepository();
        $components = $repository->getAll();

        $this->stdout(sprintf(
            "Found %d component(s), %d story(ies).\n\n",
            $repository->componentCount(),
            $repository->storyCount(),
        ), Console::FG_GREEN);

        foreach ($components as $component) {
            $flag = $component->hasErrors() ? ' [errors]' : '';
            $this->stdout(sprintf(
                "  %-28s %s (%d)%s\n",
                $component->id,
                $component->effectiveGroup(),
                $component->storyCount(),
                $flag,
            ));
        }

        $globalErrors = $repository->getErrors();
        $componentErrors = [];
        foreach ($components as $component) {
            foreach ($component->errors as $error) {
                $componentErrors[] = $error;
            }
        }
        $allErrors = array_merge($globalErrors, $componentErrors);

        if ($allErrors !== []) {
            $this->stdout("\nErrors:\n", Console::FG_YELLOW);
            foreach ($allErrors as $error) {
                $this->stdout(sprintf(
                    "  [%s] %s%s\n",
                    $error->type,
                    $error->message,
                    $error->file ? " ({$error->file})" : '',
                ), Console::FG_YELLOW);
            }
        }

        return ExitCode::OK;
    }
}
