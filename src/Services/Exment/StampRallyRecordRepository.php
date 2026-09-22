<?php

declare(strict_types=1);

namespace App\Services\Exment;

use DomainException;

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

    /**
     * @param list<string> $choices
     * @return array{column: string, choices: list<string>, record: array<string, mixed>}
     */
    public function saveChoicesByLineId(string $lineId, array $choices): array
    {
        $result = $this->findOrCreateByLineId($lineId);
        $record = $result['record'];
        $recordId = $record['id'] ?? null;

        if (!is_int($recordId) && !is_string($recordId)) {
            throw new \RuntimeException('Exment record ID is missing.');
        }

        $loopCount = $this->normalizeLoopCount($record['value']['loop_count'] ?? 1);
        $column = match ($loopCount) {
            1 => 'loop1_choices',
            2 => 'loop2_choices',
            default => 'loop3_choices',
        };

        $updatedRecord = $this->client->put($this->dataPath('/' . rawurlencode((string) $recordId)), [
            'value' => [
                $column => implode(',', $choices),
            ],
        ]);

        return [
            'column' => $column,
            'choices' => $choices,
            'record' => $updatedRecord,
        ];
    }

    /**
     * @return array{stamp: int, stamps: list<int>, alreadyAcquired: bool, record: array<string, mixed>}
     */
    public function acquireStampByLineId(string $lineId, int $stampId): array
    {
        if ($stampId < 1 || $stampId > 5) {
            throw new DomainException('invalid_stamp');
        }

        $result = $this->findOrCreateByLineId($lineId);
        $record = $result['record'];
        $recordId = $record['id'] ?? null;

        if (!is_int($recordId) && !is_string($recordId)) {
            throw new \RuntimeException('Exment record ID is missing.');
        }

        $stamps = $this->parseCollectedStamps($record['value']['collected_stamps'] ?? '');
        if (in_array($stampId, $stamps, true)) {
            return [
                'stamp' => $stampId,
                'stamps' => $stamps,
                'alreadyAcquired' => true,
                'record' => $record,
            ];
        }

        $expectedNext = count($stamps) + 1;
        if ($stampId !== $expectedNext) {
            throw new DomainException('out_of_order_stamp');
        }

        $stamps[] = $stampId;
        $updatedRecord = $this->client->put($this->dataPath('/' . rawurlencode((string) $recordId)), [
            'value' => [
                'collected_stamps' => implode(',', $stamps),
            ],
        ]);

        return [
            'stamp' => $stampId,
            'stamps' => $stamps,
            'alreadyAcquired' => false,
            'record' => $updatedRecord,
        ];
    }

    /**
     * @return list<int>
     */
    private function parseCollectedStamps(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $stamps = [];
        foreach (explode(',', $value) as $item) {
            $stampId = (int) trim($item);
            if ($stampId >= 1 && $stampId <= 5 && !in_array($stampId, $stamps, true)) {
                $stamps[] = $stampId;
            }
        }

        sort($stamps);

        return array_values($stamps);
    }

    private function normalizeLoopCount(mixed $value): int
    {
        $loopCount = (int) $value;
        if ($loopCount < 1) {
            return 1;
        }

        if ($loopCount > 3) {
            return 3;
        }

        return $loopCount;
    }

    private function dataPath(string $suffix = ''): string
    {
        return '/api/data/' . rawurlencode($this->tableKey) . $suffix;
    }
}
