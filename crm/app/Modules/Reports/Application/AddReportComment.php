<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Modules\Audit\Domain\AuditEvent;
use App\Modules\Audit\Domain\Contracts\AuditRecorderInterface;
use App\Modules\Identity\Domain\Contracts\UserFactsInterface;
use App\Modules\Reports\Domain\Contracts\ReportDirectoryInterface;
use Illuminate\Database\ConnectionInterface;

/**
 * `D-127`, §3.10 *comment*: a live recipient (`ReportAccess::reachReceived`, 404 otherwise)
 * appends a comment, at any status — it never touches the report or its token. The row and
 * its `REPORT_COMMENT_ADDED` entry (`AUD-01`) are one transaction.
 */
final readonly class AddReportComment
{
    public function __construct(
        private ReportDirectoryInterface $reports,
        private ReportAccess $access,
        private UserFactsInterface $users,
        private AuditRecorderInterface $audit,
        private ConnectionInterface $connection,
    ) {}

    /** @return array{id: string, body: string, author: array{id: string, name: string}, created_at: string} */
    public function add(string $reportId, string $body, string $actorId): array
    {
        return $this->connection->transaction(function () use ($reportId, $body, $actorId): array {
            $this->access->reachReceived($reportId, $actorId);

            $comment = $this->reports->addComment($reportId, $body, $actorId);
            $this->audit->record(AuditEvent::of('REPORT_COMMENT_ADDED'), 'report', $reportId, null, [
                'comment_id' => $comment['id'], 'body' => $body,
            ]);

            return [
                'id' => $comment['id'],
                'body' => $body,
                'author' => ['id' => $actorId, 'name' => $this->users->namesOf([$actorId])[$actorId] ?? ''],
                'created_at' => $comment['created_at'],
            ];
        });
    }
}
