<?php

namespace App\Commands\Versioning;

use App\Support\ProjectPath;
use App\Support\Release\ReadmeHeadWriter;
use App\Support\Release\SemanticVersion;
use App\Support\Release\VersionableImplementation;
use App\Support\Release\VersioningLifecycle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\spin;

#[Signature('readme:update')]
#[Description('Update the managed README header for the current project version')]
final class UpdateReadmeCommand extends Command
{
    public function handle(
        ProjectPath $projectPath,
        VersionableImplementation $implementation,
        SemanticVersion $semanticVersion,
        VersioningLifecycle $lifecycle,
        ReadmeHeadWriter $writer,
    ): int {
        $projectRoot = $projectPath->root();

        if ($projectRoot === null) {
            $this->components->error('Unable to locate the project root. Run Maintainer inside a Composer project.');

            return self::FAILURE;
        }

        $update = function () use ($projectRoot, $implementation, $semanticVersion, $lifecycle, $writer): bool {
            $versionable = $implementation->findValidated($projectRoot, $semanticVersion);
            $head = $lifecycle->readmeHead($versionable);

            if ($head === null) {
                throw new RuntimeException("Implement WithReadmeHead on {$versionable->name} to configure the README header.");
            }

            return $writer->update($projectRoot, $head, $versionable->version);
        };
        $progress = 'Updating the README header...';

        try {
            if ($this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN)) {
                $updated = spin($update, $progress);
            } else {
                $this->components->info($progress);
                $updated = $update();
            }
        } catch (Throwable $exception) {
            $this->components->error('Unable to update the README header. '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($updated) {
            $this->components->success('Updated the README header.');
        } else {
            $this->components->info('The README header is already up to date.');
        }

        return self::SUCCESS;
    }
}
