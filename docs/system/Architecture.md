---
title: Architecture Topology
date: 2026-08-21
tags:
  - architecture/topology
  - architecture/rest
  - architecture/vue3
---

# 📐 System Architecture & Topology

> [!info]
> Linked to the master hub: [[SYSTEM_KNOWLEDGE_BASE|Master Knowledge Base]]

## Multi-Layer Architecture

```mermaid
graph TD
    Client["1. Vue 3 Progressive Enhancement + DataBus.js"]
    API["2. Canonical REST Gateway (api/index.php)"]
    Services["3. Modular PHP 8 Domain Services"]
    DB["4. MariaDB 10.x Database (gncp_portal)"]

    Client --> API --> Services --> DB
```

* **Client Layer**: Vue 3 Progressive Enhancement via CDN. Event sync driven by [[Station_System|Station Workstations]] and `DataBus.js`.
* **API Gateway**: Canonical router at `api/index.php`. Enforces [[Security_Audit|Session Guards]] and rate limits. See [[API_Map|API Route Inventory]].
* **Domain Services**: Pure business logic services including `AssessmentService.php`, `QueueService.php`, and `EnrollmentService.php`.
* **Persistence Layer**: MariaDB InnoDB tables detailed in [[Database_Schema|Database Schema]].

## Related Notes
* [[Database_Schema|Database Schema & Relationships]]
* [[API_Map|API Endpoint Catalog]]
* [[Student_Workflow|Student Lifecycle State Machine]]
* [[Business_Rules|System Business Rules]]
