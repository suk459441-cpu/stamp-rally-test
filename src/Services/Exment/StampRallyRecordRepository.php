<?php

declare(strict_types=1);

namespace App\Services\Exment;

final class StampRallyRecordRepository
{
    public function __construct(
        private readonly ExmentClientInterface $client,
        private readonly string $tableKey,
    ) {
    }

    /**
     * @return array{created: bool, record: array<string, mixed>}
     */
    public function findOrCreateByLineId(string $lineId): array
    {
        $record = $this->findByLineId($lineId);
        if ($record !== null) {
            return [
                'created' => false,
                'record' => $record,
            ];
        }

        return [
            'created' => true,
            'record' => $this->createByLineId($lineId),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByLineId(string $lineId): ?array
    {
        $result = $this->client->get($this->dataPath('/query-column'), [
            'q' => "LINE_ID eq {$lineId}",
            'count' => 1,
        ]);

        foreach (($result['data'] ?? []) as $record) {
            if (is_array($record) && ($record['value']['LINE_ID'] ?? null) === $lineId) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function createByLineId(string $lineId): array
    {
        return $this->client->post($this->dataPath(), [
            'value' => [
                'LINE_ID' => $lineId,
                'loop_count' => 1,
            ],
        ]);
    }

    private function dataPath(string $suffix = ''): string
    {
        return '/api/data/' . rawurlencode($this->tableKey) . $suffix;
    }
}
