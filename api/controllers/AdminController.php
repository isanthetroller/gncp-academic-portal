<?php
/**
 * Admin Controller — Handles administrative catalog, terms, scheduling, and user management
 */
require_once __DIR__ . '/CatalogAdminController.php';
require_once __DIR__ . '/ScheduleAdminController.php';
require_once __DIR__ . '/UserAdminController.php';
require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';
require_once __DIR__ . '/../../shared/backend/services/AnalyticsService.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

class AdminController {
    private $catalogCtrl;
    private $scheduleCtrl;
    private $userCtrl;
    private $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->catalogCtrl = new CatalogAdminController($pdo);
        $this->scheduleCtrl = new ScheduleAdminController($pdo);
        $this->userCtrl = new UserAdminController($pdo);
    }

    public function getAnalytics($filters = []) {
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return AnalyticsService::getAnalytics($this->pdo, $filters);
    }

    public function getCatalog() { return $this->catalogCtrl->getCatalog(); }
    public function saveProgram($payload) { return $this->catalogCtrl->saveProgram($payload); }
    public function saveSubject($payload) { return $this->catalogCtrl->saveSubject($payload); }

    public function getSections() { return $this->scheduleCtrl->getSections(); }
    public function getTerms() { return $this->scheduleCtrl->getTerms(); }
    public function saveSection($payload) { return $this->scheduleCtrl->saveSection($payload); }
    public function saveTerm($payload) { return $this->scheduleCtrl->saveTerm($payload); }

    public function getUsers() { return $this->userCtrl->getUsers(); }
    public function saveUser($payload) { return $this->userCtrl->saveUser($payload); }
    public function resetOperatorPassword($payload) { return $this->userCtrl->resetOperatorPassword($payload); }
    public function cleanupTestUsers($payload) { return $this->userCtrl->cleanupTestUsers($payload); }

    public function getAnnouncements($filters = []) { return AnnouncementService::getAnnouncements($this->pdo, $filters); }
    public function saveAnnouncement($payload) { 
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return AnnouncementService::saveAnnouncement($this->pdo, $payload); 
    }
    public function deleteAnnouncement($payload) { 
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return AnnouncementService::deleteAnnouncement($this->pdo, $payload); 
    }
    public function uploadAnnouncementImage() { 
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return AnnouncementService::uploadImage(); 
    }

    public function getMilestones($filters = []) { return MilestoneService::getMilestones($this->pdo, $filters); }
    public function saveMilestone($payload) { 
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return MilestoneService::saveMilestone($this->pdo, $payload); 
    }
    public function deleteMilestone($payload) { 
        requireAuth(['ADMIN', 'SUPER_ADMIN']);
        return MilestoneService::deleteMilestone($this->pdo, $payload); 
    }
}


