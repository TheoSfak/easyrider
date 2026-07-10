<?php

/**
 * Thin controller adapter for mission-view.php.
 * The legacy page retains its URL while data access moves behind the feature.
 */
final class MissionViewController {
    public function __construct(private MissionViewService $service) {
    }

    public function loadMission(int $missionId): ?array {
        return $this->service->findMission($missionId);
    }

    public function loadMissionDays(int $missionId): array {
        return $this->service->findMissionDays($missionId);
    }
}
