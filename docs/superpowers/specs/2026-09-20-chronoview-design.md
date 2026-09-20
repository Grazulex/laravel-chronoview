# laravel-chronoview — Document de conception

> **Horizon, mais pour le scheduler.** Un dashboard Blade + Alpine qui montre chaque tâche planifiée, ses exécutions, ses échecs, ses runs manqués et sa sortie — sans rien changer au code de l'application.

Package : `grazulex/laravel-chronoview` · Namespace : `Grazulex\ChronoView` · PHP ^8.3 · Laravel ^12 | ^13

---

## 1. Pourquoi ce package

Le scheduler Laravel est une boîte noire en production : `schedule:run` tourne (ou pas) toutes les minutes, et on ne découvre qu'une tâche ne s'exécute plus que lorsque quelqu'un s'en plaint.

État de l'écosystème (septembre 2026) :

| Package | Ce qu'il fait | Ce qui manque |
|---|---|---|
| `spatie/laravel-schedule-monitor` | Log en base de chaque run (start/end/fail/skip), sync Oh Dear | Pas d'interface, monitoring des runs manqués payant (Oh Dear) |
| Watchtower | Dashboard scheduler + queues + jobs + exceptions | Fait tout, donc pas focalisé ; très récent |
| `hosmelq/laravel-pulse-schedule` | Card Pulse listant les tâches | Pas d'historique, pas de résultats, dépend de Pulse |
| `chege-simon/vista` | « Horizon pour le scheduler » en Vue | Aucune traction (7 installs) |

**Le créneau** : un outil dédié, zéro config, qui répond à trois questions — *mes tâches tournent-elles ? quand ? avec quel résultat ?* — et qui détecte ce qu'on ne voit jamais : les runs qui n'ont **pas** eu lieu.

## 2. Principes

1. **Zéro instrumentation** : on écoute les events natifs du scheduler. Aucun `->monitor()` à chaîner sur chaque tâche.
2. **Aucune dépendance front chez l'hôte** : Blade + Alpine embarqué (`resources/dist/alpine.min.js`), CSS maison, assets servis directement depuis le package via une route — pas de `vendor:publish` d'assets, pas d'assets périmés après upgrade.
3. **Stockage en base** (pas de Redis requis), connexion configurable, pruning automatique.
4. **Le monitoring ne casse jamais le scheduler** : chaque listener est enveloppé dans un `try/catch` + `report()`.
5. **Accès façon Horizon** : callback `ChronoView::auth()` ou gate `viewChronoView`, ouvert uniquement en `local` par défaut.
6. **Stack Grazulex** : Pest 3/4, Larastan 3 (niveau 6), Pint (preset laravel + strict types + final), Rector 2, Testbench 10/11, CI matrix PHP 8.3/8.4 × Laravel 12/13.

## 3. Fonctionnalités

### v0.1 (MVP)
- Enregistrement de chaque run : statut, déclencheur (schedule / manuel), heure due, début, fin, durée, exit code, hôte, sortie capturée, exception + stack trace.
- Sync automatique des tâches du schedule (type, nom lisible, expression cron traduite, timezone, flags `runInBackground` / `withoutOverlapping` / `onOneServer`).
- **Détection des runs manqués** (heure due passée sans aucun run ni skip).
- **Heartbeat du scheduler** : si `chronoview:check` ne bat plus, le cron système lui-même est cassé — c'est affiché en rouge en haut du dashboard.
- Fermeture des runs « zombies » (statut `running` depuis trop longtemps → `failed`).
- Dashboard : vue d'ensemble (santé, KPIs 24 h, tâches en difficulté, prochaines exécutions, derniers problèmes), liste des tâches filtrable, détail d'une tâche (historique paginé, taux de succès 7 j, sparkline des durées), détail d'un run (sortie, exception).
- Actions : **Run now** (job queue-able) et **Pause / Reprise** sans redéploiement.
- Events publics pour brancher ses alertes : `TaskRunFailed`, `TaskRunMissed`, `SchedulerDown`.
- Commandes : `chronoview:install`, `chronoview:sync`, `chronoview:check`, `chronoview:prune`.

### v0.2+
- Notifications intégrées (mail, Slack, webhook) avec seuils (N échecs consécutifs, durée anormale = x × médiane).
- Endpoint JSON `/chronoview/api/health` pour un uptime monitor externe.
- Multi-serveurs : vue par hôte, alerte si un hôte cesse de battre.
- Card Pulse optionnelle.
- Export / comparaison entre environnements.

## 4. Architecture

```
src/
├── ChronoView.php                  Manager : auth(), check(), stats()
├── ChronoViewServiceProvider.php   Bindings, routes, vues, migrations, subscriber, schedule interne
├── Facades/ChronoView.php
├── Models/
│   ├── MonitoredTask.php           Une tâche du schedule (clé stable, cron, état, santé)
│   └── TaskRun.php                 Une exécution (running/success/failed/skipped/missed)
├── Support/
│   ├── TaskDefinition.php          Event du scheduler → définition immuable + clé sha1
│   ├── ScheduleInspector.php       Lit le schedule, le « décore » (pause + capture output), retrouve un event par clé
│   ├── Recorder.php                Toutes les écritures : sync, starting, finished, failed, skipped, missed
│   ├── MissedRunDetector.php       Runs manqués + runs zombies
│   └── Heartbeat.php               Table heartbeats (un beat par hôte)
├── Listeners/ScheduleEventSubscriber.php
├── Jobs/RunScheduledTask.php       « Run now »
├── Commands/{Check,Sync,Prune,Install}Command.php
├── Events/{TaskRunFailed,TaskRunMissed,SchedulerDown}.php
└── Http/
    ├── Middleware/Authorize.php
    └── Controllers/{Dashboard,Task,Run,Action,Asset}Controller.php
```

### Flux d'un run planifié

```
schedule:run
  │
  ├─ CommandStarting("schedule:run")  ──► ScheduleInspector::decorate()
  │      (le schedule est complet ici, routes/console.php inclus)
  │        • ajoute ->when(!paused) à chaque event
  │        • redirige l'output vers storage/framework/chronoview/<key>.log
  │
  ├─ pour chaque event dû :
  │     filtersPass() == false ──► ScheduledTaskSkipped ──► Recorder::skipped()
  │     ScheduledTaskStarting ────────────────────────────► Recorder::starting()   (run = running)
  │     exécution
  │     ScheduledTaskFinished ────────────────────────────► Recorder::finished()  (exit code → success/failed, lit l'output)
  │     ScheduledTaskFailed (exception) ──────────────────► Recorder::failed()
  │
  └─ tâches runInBackground : schedule:finish (autre process)
        ScheduledBackgroundTaskFinished ─────────────────► Recorder::finished(exitCode)
```

Le run ouvert est retrouvé **en base** (dernier `running` de la tâche), jamais en mémoire : c'est ce qui permet aux tâches en arrière-plan, terminées dans un autre process, d'être fermées correctement.

### Boucle de contrôle (`chronoview:check`, chaque minute, enregistrée par le package)

1. `Heartbeat::beat()` — upsert `hostname → now`.
2. `Recorder::syncSchedule()` — upsert de chaque tâche, `seen_at = now`.
3. `MissedRunDetector::detect()` — pour chaque tâche active vue récemment :
   - `due = previousDueAt(now − grace)` (dernière heure due vieille d'au moins `grace` secondes) ;
   - ignorée si la tâche a été créée après `due` ;
   - **manquée** s'il n'existe aucun run (hors `missed`) avec `expected_at = due` ou `started_at ∈ [due, due + grace + 60 s]` ;
   - dédoublonnée par `(task, expected_at, missed)`.
4. `MissedRunDetector::closeStaleRuns()` — `running` depuis plus de `stale_after` → `failed`.

`chronoview:prune` tourne chaque jour (`onOneServer`).

## 5. Décisions techniques (et pourquoi)

| Sujet | Décision | Raison |
|---|---|---|
| **Identité d'une tâche** | `key = sha1(type \| nom \| expression \| timezone)` | Stable entre déploiements, sans `->name()` obligatoire. Nom = description, sinon commande normalisée (`artisan inspire`), sinon `Closure at: routes/console.php:12` par réflexion. Un job (`Schedule::job`) est reconnu car sa description est un nom de classe existant. |
| **Moment de la décoration** | Listener `CommandStarting` pour `schedule:run/work/test/finish` | `afterResolving(Schedule)` se déclenche **avant** que `routes/console.php` ne soit exécuté (c'est l'appelant qui résout le singleton) — on raterait les tâches. Au démarrage de la commande, le schedule est complet. |
| **Schedule hors console** (dashboard, worker) | `ScheduleInspector::schedule()` appelle `Kernel::bootstrap()` du kernel console | C'est ce que fait `Artisan::call()` en HTTP : ça charge `routes/console.php`. Nécessaire pour « Run now » sur queue `sync`. |
| **Capture de l'output** | `sendOutputTo()` vers un fichier par clé, **seulement** si l'output est encore `/dev/null` | On ne touche jamais à une redirection posée par le développeur ; on ne supprime que notre propre fichier. Tronqué à `max_output` (64 Ko, on garde la fin). |
| **Échec des commandes artisan** | Vérifier `exitCode` dans `ScheduledTaskFinished` | Laravel ne lève `ScheduledTaskFailed` que sur exception (closures). Un sous-process qui retourne 1 arrive dans *Finished*. |
| **Pause** | `->when(fn => !paused)` injecté à la décoration ; clés en pause chargées **une fois par process** | `schedule:run` est un process neuf chaque minute → une pause est effective dans la minute, avec une seule requête. Les skips dus à une pause ne sont pas journalisés (bruit). |
| **Run now** | Job `RunScheduledTask` qui rejoue `Starting/Finished/Failed` via le Recorder, `trigger = manual` | Même trace qu'un run planifié. Pour une tâche `runInBackground`, le job ne ferme pas le run : `schedule:finish` s'en charge. |
| **Skips** | Enregistrés par défaut | Un skip prouve que le scheduler a *considéré* la tâche : sans ça, une tâche filtrée par `environments()` ressemblerait à un run manqué. |
| **Runs manqués vs scheduler mort** | Deux mécanismes distincts | Si le cron ne tourne plus, `check` ne tourne pas non plus : c'est le **heartbeat** (lu par le dashboard) qui le révèle. La détection des manqués couvre les tâches individuelles. |
| **Assets** | Route `chronoview/assets/{file}` servant `resources/dist/*` avec cache 24 h | Pas de publish, pas de désynchronisation, pas de dépendance à Vite chez l'hôte. |
| **Filtrage de nos propres commandes** | `isOwnCommand()` sur `chronoview:(check\|prune\|sync)` | Sinon le dashboard serait pollué par ses propres runs. |
| **Rafraîchissement** | Rechargement de page toutes les `refresh` s (Alpine, désactivable) | Suffisant pour un v0.1 ; des endpoints JSON + polling partiel viendront en v0.2. |

## 6. Modèle de données

Préfixe configurable (`chronoview_`), connexion configurable.

**`tasks`** — une ligne par tâche du schedule
`key` (sha1, unique) · `name` · `type` (command / closure / job / exec) · `command` · `expression` · `timezone` · `description` · `run_in_background` · `without_overlapping` · `on_one_server` · `paused_at` · `last_started_at` · `last_finished_at` · `last_status` · `consecutive_failures` · `seen_at` · timestamps

**`runs`** — une ligne par exécution (ou non-exécution)
`task_id` · `status` (running / success / failed / skipped / missed) · `trigger` (schedule / manual) · `expected_at` (heure due) · `started_at` · `finished_at` · `duration_ms` · `exit_code` · `memory_peak` · `hostname` · `output` · `exception` · timestamps
Index : `(task_id, expected_at)`, `(task_id, started_at)`, `status`, `started_at`.

**`heartbeats`** — `hostname` (PK) · `beat_at`

Santé d'une tâche (`MonitoredTask::health()`) : `paused` → `unhealthy` (dernier statut failed/missed) → `running` → `healthy` → `unknown`.

## 7. Configuration (`config/chronoview.php`)

```php
'enabled'    => env('CHRONOVIEW_ENABLED', true),
'path'       => env('CHRONOVIEW_PATH', 'chronoview'),
'domain'     => env('CHRONOVIEW_DOMAIN'),
'middleware' => ['web', 'chronoview.auth'],
'refresh'    => env('CHRONOVIEW_REFRESH', 15),        // secondes, 0 = off
'connection' => env('CHRONOVIEW_DB_CONNECTION'),
'table_prefix' => 'chronoview_',
'record'  => ['output' => true, 'max_output' => 64 * 1024, 'skipped' => true],
'check'   => ['enabled' => true, 'grace' => 90, 'heartbeat_timeout' => 180, 'stale_after' => 6 * 3600],
'actions' => ['run_now' => true, 'queue_connection' => env(...), 'queue' => env(...), 'pause' => true],
'prune'   => ['keep_days' => 14],
```

## 8. Utilisation

```bash
composer require grazulex/laravel-chronoview
php artisan chronoview:install      # publie la config, migre, synchronise
```

```php
// AppServiceProvider::boot()
ChronoView::auth(fn ($request) => $request->user()?->isAdmin());
// ou
Gate::define('viewChronoView', fn (User $user) => $user->isAdmin());
```

Le cron habituel suffit : `* * * * * php artisan schedule:run`. Le package planifie lui-même `chronoview:check` (chaque minute) et `chronoview:prune` (chaque jour).

Alertes maison :

```php
Event::listen(TaskRunFailed::class, fn ($e) => Notification::route('slack', ...)->notify(new TaskFailedNotification($e->run)));
Event::listen(TaskRunMissed::class, ...);
```

## 9. Écrans

1. **Overview** — bandeau « Scheduler alive / down » + dernier heartbeat par hôte ; tuiles 24 h (runs, succès %, échecs, manqués, durée moyenne, en cours) ; « Needs attention » ; « Up next » (8 prochaines exécutions) ; derniers problèmes ; derniers runs.
2. **Tasks** — tableau filtrable (healthy / unhealthy / paused), recherche instantanée (Alpine), colonnes : santé, nom, type, cron en clair, dernier run, prochain run, actions.
3. **Task** — en-tête (cron, timezone, flags), résumé 7 j, sparkline SVG des 40 dernières durées, historique paginé, boutons Run now / Pause.
4. **Run** — métadonnées, sortie (bloc `<pre>`), exception + trace.

Thème clair/sombre (`prefers-color-scheme` + bascule mémorisée en `localStorage`), responsive, aucune requête externe.

## 10. État du scaffold

### Fait
- `composer.json` (conventions Grazulex), `pint.json`, `phpstan.neon`, `rector.php`, `phpunit.xml`, CI GitHub Actions, LICENSE, CHANGELOG.
- `config/chronoview.php`.
- Migration des trois tables.
- Modèles `MonitoredTask`, `TaskRun`.
- `TaskDefinition`, `ScheduleInspector`, `Recorder`, `MissedRunDetector`, `Heartbeat`.
- `ScheduleEventSubscriber` (6 events).
- Commandes `check`, `sync`, `prune`, `install`.
- `ChronoView` (manager) + façade, `RunScheduledTask`, middleware `Authorize`.
- `ChronoViewServiceProvider`, `routes/web.php`.
- `resources/dist/alpine.min.js` (Alpine 3.17.3, via npm).

### À faire
- Contrôleurs `Dashboard`, `Task`, `Run`, `Action`, `Asset` (code prêt, non écrit — voir §4 pour le contrat).
- Vues Blade : `layouts/app`, `dashboard`, `tasks/index`, `tasks/show`, `runs/index`, `runs/show`, partials (badge de statut, sparkline, flash).
- `resources/dist/chronoview.css`.
- Tests Pest (Testbench, SQLite en mémoire) :
  - `TaskDefinitionTest` : nom/clé pour command, exec, closure, job ; normalisation du binaire PHP.
  - `RecorderTest` : starting → finished (exit 0 / exit 1), failed avec exception, skipped, output capturé et tronqué.
  - `MissedRunDetectorTest` : manqué détecté, pas de doublon, pas de faux positif si run ou skip présent, pas de faux positif pour une tâche créée après l'heure due (utiliser `Carbon::setTestNow`).
  - `HeartbeatTest` : alive / down selon `heartbeat_timeout`.
  - `DashboardTest` : 403 hors local sans gate, 200 avec `ChronoView::auth`, pages tasks/runs, actions run/pause/resume, `enabled=false` → 404.
  - `ScheduleIntegrationTest` : un vrai `schedule:run` sur un schedule de test (closure OK, closure qui lève, commande `inspire`) et vérification des runs en base.
- README (installation, captures, config, events, FAQ « pourquoi un run est marqué manqué ? »).
- Captures d'écran pour le README et le post LinkedIn / Laravel News.

### Points à valider au premier `composer install`
- Signature exacte de `CronExpression::getPreviousRunDate($from, 0, true)` (le 3ᵉ paramètre = inclure l'instant courant).
- Type de `$event->timezone` (string ou `DateTimeZone`) selon la version — géré par `TaskDefinition::timezoneName()`.
- `ScheduledTaskFinished::$runtime` (float, secondes) : utilisé pour la durée.
- Contrainte de `->when()` ajoutée après la construction : vérifier qu'aucun cache d'`filters` n'est figé (non, `filters` est un tableau public lu à l'exécution).

## 11. Feuille de route de publication

1. Terminer vues + tests, `composer full` vert sur la matrice CI.
2. Tester dans une vraie app avec `schedule:work` pendant une heure (closures, commande qui échoue, tâche `runInBackground`, tâche en pause).
3. Tag `v0.1.0`, soumission Packagist, GitHub Discussions ouvertes.
4. Article FR/EN LinkedIn + soumission Laravel News : angle « les runs qui n'ont pas eu lieu ».
