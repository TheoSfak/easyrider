<?php
/** @var array $mission */
/** @var bool $canViewRideEvents */
/** @var bool $canManageMissions */
/** @var bool $hasReplayData */
/** @var bool $isMultiDayMission */
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <?= h($mission['title']) ?>
            <?php if (!empty($mission['type_name'])): ?>
                <span class="badge bg-<?= h($mission['type_color'] ?? 'secondary') ?>">
                    <i class="bi <?= h($mission['type_icon'] ?? 'bi-flag') ?>"></i>
                    <?= h($mission['type_name']) ?>
                </span>
            <?php endif; ?>
            <?php if ($mission['is_urgent']): ?>
                <span class="badge bg-danger">Επείγον</span>
            <?php endif; ?>
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="missions.php">Δράσεις</a></li>
                <li class="breadcrumb-item active"><?= h($mission['title']) ?></li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2 flex-wrap justify-content-end">
        <a href="ride-mode.php?mission_id=<?= $mission['id'] ?>" class="btn btn-primary">
            <i class="bi bi-broadcast-pin me-1"></i>Ride Mode
        </a>
        <?php if ($canViewRideEvents): ?>
            <a href="ride-report.php?mission_id=<?= $mission['id'] ?>" class="btn btn-outline-dark">
                <i class="bi bi-file-earmark-text me-1"></i>Ride Report
            </a>
        <?php endif; ?>
        <?php if ($canManageMissions): ?>
            <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
        <?php if ($mission['status'] === STATUS_COMPLETED && $hasReplayData): ?>
            <button type="button" class="btn btn-outline-primary"
                    data-bs-toggle="modal" data-bs-target="<?= $isMultiDayMission ? '#dayReplayModal' : '#rideReplayModal' ?>">
                <i class="bi bi-film me-1"></i>Actual Replay
            </button>
        <?php endif; ?>
        <?php if ($canManageMissions && $hasReplayData): ?>
            <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#replayShareModal">
                <i class="bi bi-share me-1"></i>Δημόσιο Link
            </button>
        <?php endif; ?>
    </div>
</div>
