<?php

/**
 * Mission feature persistence boundary.
 * Keep mission SQL here as pages are gradually extracted from legacy scripts.
 */
final class MissionRepository {
    public function findForView(int $missionId): ?array {
        return dbFetchOne(
            "SELECT m.*, u.name as creator_name, r.name as responsible_name,
                    mt.name as type_name, mt.color as type_color, mt.icon as type_icon
             FROM missions m
             LEFT JOIN users u ON m.created_by = u.id
             LEFT JOIN users r ON m.responsible_user_id = r.id
             LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
             WHERE m.id = ?",
            [$missionId]
        );
    }

    public function findDays(int $missionId): array {
        return dbFetchAll(
            'SELECT * FROM mission_days WHERE mission_id = ? ORDER BY day_number',
            [$missionId]
        );
    }
}
