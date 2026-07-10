<?php

/**
 * Read-model service for the mission detail feature.
 */
final class MissionViewService {
    public function __construct(private MissionRepository $missions) {
    }

    public function findMission(int $missionId): ?array {
        return $this->missions->findForView($missionId);
    }

    public function findMissionDays(int $missionId): array {
        return $this->missions->findDays($missionId);
    }
}
