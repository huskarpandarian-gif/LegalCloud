<?php

namespace App\Console\Commands;

use App\Models\AssistantMessage;
use App\Models\AuditEvent;
use App\Models\Citation;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\LegalCase;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class EncryptSensitiveData extends Command
{
    protected $signature = 'legalcloud:encrypt-sensitive-data {--dry-run : Report legacy records without changing them}';

    protected $description = 'Encrypt legacy document and assistant content with the current Laravel APP_KEY';

    public function handle(): int
    {
        if (! config('app.key')) {
            $this->error('APP_KEY must be configured before encrypting stored data.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $counts = [
            'files' => 0,
            'texts' => 0,
            'fileNames' => 0,
            'chunks' => 0,
            'messages' => 0,
            'citations' => 0,
            'caseReferences' => 0,
            'caseTitles' => 0,
            'caseDescriptions' => 0,
            'documentTitles' => 0,
            'documentDescriptions' => 0,
            'userNames' => 0,
            'userEmails' => 0,
            'conversationTitles' => 0,
            'jobResults' => 0,
            'notificationTitles' => 0,
            'notificationBodies' => 0,
            'auditChanges' => 0,
            'auditIps' => 0,
        ];

        try {
            DocumentVersion::query()
                ->where(fn ($query) => $query->where('file_encryption_version', 0)
                    ->orWhere('content_encryption_version', 0)
                    ->orWhere('file_name_encryption_version', 0))
                ->chunkById(100, function ($versions) use ($dryRun, &$counts): void {
                    foreach ($versions as $version) {
                        if ((int) $version->file_encryption_version === 0) {
                            $counts['files']++;
                            if (! $dryRun) {
                                $this->encryptStoredFile($version);
                            }
                        }

                        if ((int) $version->content_encryption_version === 0 && $version->extracted_text !== null) {
                            $counts['texts']++;
                            if (! $dryRun) {
                                $version->extracted_text = $version->extracted_text;
                                $version->save();
                            }
                        }

                        if ((int) $version->file_name_encryption_version === 0) {
                            $counts['fileNames']++;
                            if (! $dryRun) {
                                $version->file_name = $version->file_name;
                                $version->save();
                            }
                        }
                    }
                });

            $counts['caseReferences'] = $this->encryptRows(LegalCase::query()->where('reference_encryption_version', 0), 'reference', $dryRun);
            $counts['caseTitles'] = $this->encryptRows(LegalCase::query()->where('title_encryption_version', 0), 'title', $dryRun);
            $counts['caseDescriptions'] = $this->encryptRows(LegalCase::query()->where('description_encryption_version', 0)->whereNotNull('description'), 'description', $dryRun);
            $counts['documentTitles'] = $this->encryptRows(Document::query()->where('title_encryption_version', 0), 'title', $dryRun);
            $counts['documentDescriptions'] = $this->encryptRows(Document::query()->where('description_encryption_version', 0)->whereNotNull('description'), 'description', $dryRun);
            $counts['userNames'] = $this->encryptRows(User::query()->where('name_encryption_version', 0), 'name', $dryRun);
            $counts['userEmails'] = $this->encryptRows(User::query()->where('email_encryption_version', 0), 'email', $dryRun);
            $counts['conversationTitles'] = $this->encryptRows(Conversation::query()->where('title_encryption_version', 0), 'title', $dryRun);
            $counts['jobResults'] = $this->encryptRows(ProcessingJob::query()->where('result_encryption_version', 0)->whereNotNull('result_text'), 'result_text', $dryRun);
            $counts['chunks'] = $this->encryptRows(DocumentChunk::query()->where('encryption_version', 0), 'content', $dryRun);
            $counts['messages'] = $this->encryptRows(AssistantMessage::query()->where('content_encryption_version', 0), 'content', $dryRun);
            $counts['citations'] = $this->encryptRows(Citation::query()->where('encryption_version', 0), 'excerpt', $dryRun);
            $counts['notificationTitles'] = $this->encryptRows(UserNotification::query()->where('title_encryption_version', 0), 'title', $dryRun);
            $counts['notificationBodies'] = $this->encryptRows(UserNotification::query()->where('body_encryption_version', 0)->whereNotNull('body'), 'body', $dryRun);
            $counts['auditChanges'] = $this->encryptRows(AuditEvent::query()->whereNull('encrypted_changes')->whereNotNull('changes'), 'changes', $dryRun);
            $counts['auditIps'] = $this->encryptRows(AuditEvent::query()->whereNull('encrypted_ip_address')->whereNotNull('ip_address'), 'ip_address', $dryRun);
        } catch (Throwable $exception) {
            $this->error('Encryption backfill stopped; rerun after fixing the reported storage or key issue.');
            report($exception);

            return self::FAILURE;
        }

        $summary = collect($counts)->map(fn (int $count, string $type) => "{$type}={$count}")->implode(', ');
        $this->info(($dryRun ? 'Dry run; no values changed. ' : 'Backfill complete. ').$summary);

        return self::SUCCESS;
    }

    private function encryptStoredFile(DocumentVersion $version): void
    {
        $disk = Storage::disk($version->disk);
        $oldPath = $version->path;
        $newPath = $oldPath.'.'.Str::uuid().'.enc';
        $encryptedContents = Crypt::encryptString($disk->get($oldPath));

        if (! $disk->put($newPath, $encryptedContents)) {
            throw new \RuntimeException('Unable to write encrypted document file.');
        }

        try {
            $version->forceFill(['path' => $newPath, 'file_encryption_version' => 1])->save();
        } catch (Throwable $exception) {
            $disk->delete($newPath);
            throw $exception;
        }

        $disk->delete($oldPath);
    }

    private function encryptRows($query, string $field, bool $dryRun): int
    {
        $count = 0;
        $query->chunkById(100, function ($rows) use ($field, $dryRun, &$count): void {
            foreach ($rows as $row) {
                $count++;
                if (! $dryRun) {
                    $row->{$field} = $row->{$field};
                    $row->save();
                }
            }
        });

        return $count;
    }
}
