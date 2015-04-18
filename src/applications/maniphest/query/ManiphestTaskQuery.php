<?php

/**
 * Query tasks by specific criteria. This class uses the higher-performance
 * but less-general Maniphest indexes to satisfy queries.
 */
final class ManiphestTaskQuery extends PhabricatorCursorPagedPolicyAwareQuery {

  private $taskIDs             = array();
  private $taskPHIDs           = array();
  private $authorPHIDs         = array();
  private $ownerPHIDs          = array();
  private $includeUnowned      = null;
  private $projectPHIDs        = array();
  private $xprojectPHIDs       = array();
  private $subscriberPHIDs     = array();
  private $anyProjectPHIDs     = array();
  private $anyUserProjectPHIDs = array();
  private $includeNoProject    = null;
  private $dateCreatedAfter;
  private $dateCreatedBefore;
  private $dateModifiedAfter;
  private $dateModifiedBefore;

  private $fullTextSearch   = '';

  private $status           = 'status-any';
  const STATUS_ANY          = 'status-any';
  const STATUS_OPEN         = 'status-open';
  const STATUS_CLOSED       = 'status-closed';
  const STATUS_RESOLVED     = 'status-resolved';
  const STATUS_WONTFIX      = 'status-wontfix';
  const STATUS_INVALID      = 'status-invalid';
  const STATUS_SPITE        = 'status-spite';
  const STATUS_DUPLICATE    = 'status-duplicate';

  private $statuses;
  private $priorities;
  private $subpriorities;

  private $groupBy          = 'group-none';
  const GROUP_NONE          = 'group-none';
  const GROUP_PRIORITY      = 'group-priority';
  const GROUP_OWNER         = 'group-owner';
  const GROUP_STATUS        = 'group-status';
  const GROUP_PROJECT       = 'group-project';

  private $orderBy          = 'order-modified';
  const ORDER_PRIORITY      = 'order-priority';
  const ORDER_CREATED       = 'order-created';
  const ORDER_MODIFIED      = 'order-modified';
  const ORDER_TITLE         = 'order-title';

  private $needSubscriberPHIDs;
  private $needProjectPHIDs;
  private $blockingTasks;
  private $blockedTasks;
  private $projectPolicyCheckFailed = false;

  public function withAuthors(array $authors) {
    $this->authorPHIDs = $authors;
    return $this;
  }

  public function withIDs(array $ids) {
    $this->taskIDs = $ids;
    return $this;
  }

  public function withPHIDs(array $phids) {
    $this->taskPHIDs = $phids;
    return $this;
  }

  public function withOwners(array $owners) {
    $this->includeUnowned = false;
    foreach ($owners as $k => $phid) {
      if ($phid == ManiphestTaskOwner::OWNER_UP_FOR_GRABS || $phid === null) {
        $this->includeUnowned = true;
        unset($owners[$k]);
        break;
      }
    }
    $this->ownerPHIDs = $owners;
    return $this;
  }

  public function withAllProjects(array $projects) {
    $this->includeNoProject = false;
    foreach ($projects as $k => $phid) {
      if ($phid == ManiphestTaskOwner::PROJECT_NO_PROJECT) {
        $this->includeNoProject = true;
        unset($projects[$k]);
      }
    }
    $this->projectPHIDs = $projects;
    return $this;
  }

  /**
   * Add an additional "all projects" constraint to existing filters.
   *
   * This is used by boards to supplement queries.
   *
   * @param list<phid> List of project PHIDs to add to any existing constraint.
   * @return this
   */
  public function addWithAllProjects(array $projects) {
    if ($this->projectPHIDs === null) {
      $this->projectPHIDs = array();
    }

    return $this->withAllProjects(array_merge($this->projectPHIDs, $projects));
  }

  public function withoutProjects(array $projects) {
    $this->xprojectPHIDs = $projects;
    return $this;
  }

  public function withStatus($status) {
    $this->status = $status;
    return $this;
  }

  public function withStatuses(array $statuses) {
    $this->statuses = $statuses;
    return $this;
  }

  public function withPriorities(array $priorities) {
    $this->priorities = $priorities;
    return $this;
  }

  public function withSubpriorities(array $subpriorities) {
    $this->subpriorities = $subpriorities;
    return $this;
  }

  public function withSubscribers(array $subscribers) {
    $this->subscriberPHIDs = $subscribers;
    return $this;
  }

  public function withFullTextSearch($fulltext_search) {
    $this->fullTextSearch = $fulltext_search;
    return $this;
  }

  public function setGroupBy($group) {
    $this->groupBy = $group;
    return $this;
  }

  public function setOrderBy($order) {
    $this->orderBy = $order;
    return $this;
  }

  public function withAnyProjects(array $projects) {
    $this->anyProjectPHIDs = $projects;
    return $this;
  }

  public function withAnyUserProjects(array $users) {
    $this->anyUserProjectPHIDs = $users;
    return $this;
  }

  /**
   * True returns tasks that are blocking other tasks only.
   * False returns tasks that are not blocking other tasks only.
   * Null returns tasks regardless of blocking status.
   */
  public function withBlockingTasks($mode) {
    $this->blockingTasks = $mode;
    return $this;
  }

  public function shouldJoinBlockingTasks() {
    return $this->blockingTasks !== null;
  }

  /**
   * True returns tasks that are blocked by other tasks only.
   * False returns tasks that are not blocked by other tasks only.
   * Null returns tasks regardless of blocked by status.
   */
  public function withBlockedTasks($mode) {
    $this->blockedTasks = $mode;
    return $this;
  }

  public function shouldJoinBlockedTasks() {
    return $this->blockedTasks !== null;
  }

  public function withDateCreatedBefore($date_created_before) {
    $this->dateCreatedBefore = $date_created_before;
    return $this;
  }

  public function withDateCreatedAfter($date_created_after) {
    $this->dateCreatedAfter = $date_created_after;
    return $this;
  }

  public function withDateModifiedBefore($date_modified_before) {
    $this->dateModifiedBefore = $date_modified_before;
    return $this;
  }

  public function withDateModifiedAfter($date_modified_after) {
    $this->dateModifiedAfter = $date_modified_after;
    return $this;
  }

  public function needSubscriberPHIDs($bool) {
    $this->needSubscriberPHIDs = $bool;
    return $this;
  }

  public function needProjectPHIDs($bool) {
    $this->needProjectPHIDs = $bool;
    return $this;
  }

  protected function newResultObject() {
    return new ManiphestTask();
  }

  protected function willExecute() {
    // Make sure the user can see any projects specified in this
    // query FIRST.
    if ($this->projectPHIDs) {
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($this->projectPHIDs)
        ->execute();
      $projects = mpull($projects, null, 'getPHID');
      foreach ($this->projectPHIDs as $index => $phid) {
        $project = idx($projects, $phid);
        if (!$project) {
          unset($this->projectPHIDs[$index]);
          continue;
        }
      }
      if (!$this->projectPHIDs) {
        $this->projectPolicyCheckFailed = true;
      }
      $this->projectPHIDs = array_values($this->projectPHIDs);
    }

    // If we already have an order vector, use it as provided.
    // TODO: This is a messy hack to make setOrderVector() stronger than
    // setPriority().
    $vector = $this->getOrderVector();
    $keys = mpull(iterator_to_array($vector), 'getOrderKey');
    if (array_values($keys) !== array('id')) {
      return;
    }

    $parts = array();
    switch ($this->groupBy) {
      case self::GROUP_NONE:
        break;
      case self::GROUP_PRIORITY:
        $parts[] = array('priority');
        break;
      case self::GROUP_OWNER:
        $parts[] = array('owner');
        break;
      case self::GROUP_STATUS:
        $parts[] = array('status');
        break;
      case self::GROUP_PROJECT:
        $parts[] = array('project');
        break;
    }

    if ($this->applicationSearchOrders) {
      $columns = array();
      foreach ($this->applicationSearchOrders as $order) {
        $part = 'custom:'.$order['key'];
        if ($order['ascending']) {
          $part = '-'.$part;
        }
        $columns[] = $part;
      }
      $columns[] = 'id';
      $parts[] = $columns;
    } else {
      switch ($this->orderBy) {
        case self::ORDER_PRIORITY:
          $parts[] = array('priority', 'subpriority', 'id');
          break;
        case self::ORDER_CREATED:
          $parts[] = array('id');
          break;
        case self::ORDER_MODIFIED:
          $parts[] = array('updated', 'id');
          break;
        case self::ORDER_TITLE:
          $parts[] = array('title', 'id');
          break;
      }
    }

    $parts = array_mergev($parts);
    // We may have a duplicate column if we are both ordering and grouping
    // by priority.
    $parts = array_unique($parts);
    $this->setOrderVector($parts);
  }

  protected function loadPage() {

    if ($this->projectPolicyCheckFailed) {
      throw new PhabricatorEmptyQueryException();
    }

    $task_dao = new ManiphestTask();
    $conn = $task_dao->establishConnection('r');

    $where = array();
    $where[] = $this->buildTaskIDsWhereClause($conn);
    $where[] = $this->buildTaskPHIDsWhereClause($conn);
    $where[] = $this->buildStatusWhereClause($conn);
    $where[] = $this->buildStatusesWhereClause($conn);
    $where[] = $this->buildDependenciesWhereClause($conn);
    $where[] = $this->buildAuthorWhereClause($conn);
    $where[] = $this->buildOwnerWhereClause($conn);
    $where[] = $this->buildProjectWhereClause($conn);
    $where[] = $this->buildAnyProjectWhereClause($conn);
    $where[] = $this->buildAnyUserProjectWhereClause($conn);
    $where[] = $this->buildXProjectWhereClause($conn);
    $where[] = $this->buildFullTextWhereClause($conn);

    if ($this->dateCreatedAfter) {
      $where[] = qsprintf(
        $conn,
        'task.dateCreated >= %d',
        $this->dateCreatedAfter);
    }

    if ($this->dateCreatedBefore) {
      $where[] = qsprintf(
        $conn,
        'task.dateCreated <= %d',
        $this->dateCreatedBefore);
    }

    if ($this->dateModifiedAfter) {
      $where[] = qsprintf(
        $conn,
        'task.dateModified >= %d',
        $this->dateModifiedAfter);
    }

    if ($this->dateModifiedBefore) {
      $where[] = qsprintf(
        $conn,
        'task.dateModified <= %d',
        $this->dateModifiedBefore);
    }

    if ($this->priorities) {
      $where[] = qsprintf(
        $conn,
        'task.priority IN (%Ld)',
        $this->priorities);
    }

    if ($this->subpriorities) {
      $where[] = qsprintf(
        $conn,
        'task.subpriority IN (%Lf)',
        $this->subpriorities);
    }

    $where[] = $this->buildPagingClause($conn);

    $where = $this->formatWhereClause($where);

    $having = '';
    $count = '';

    if (count($this->projectPHIDs) > 1) {
      // We want to treat the query as an intersection query, not a union
      // query. We sum the project count and require it be the same as the
      // number of projects we're searching for.

      $count = ', COUNT(project.dst) projectCount';
      $having = qsprintf(
        $conn,
        'HAVING projectCount = %d',
        count($this->projectPHIDs));
    }

    $group_column = '';
    switch ($this->groupBy) {
      case self::GROUP_PROJECT:
        $group_column = qsprintf(
          $conn,
          ', projectGroupName.indexedObjectPHID projectGroupPHID');
        break;
    }

    $rows = queryfx_all(
      $conn,
      'SELECT task.* %Q %Q FROM %T task %Q %Q %Q %Q %Q %Q',
      $count,
      $group_column,
      $task_dao->getTableName(),
      $this->buildJoinsClause($conn),
      $where,
      $this->buildGroupClause($conn),
      $having,
      $this->buildOrderClause($conn),
      $this->buildLimitClause($conn));

    switch ($this->groupBy) {
      case self::GROUP_PROJECT:
        $data = ipull($rows, null, 'id');
        break;
      default:
        $data = $rows;
        break;
    }

    $tasks = $task_dao->loadAllFromArray($data);

    switch ($this->groupBy) {
      case self::GROUP_PROJECT:
        $results = array();
        foreach ($rows as $row) {
          $task = clone $tasks[$row['id']];
          $task->attachGroupByProjectPHID($row['projectGroupPHID']);
          $results[] = $task;
        }
        $tasks = $results;
        break;
    }

    return $tasks;
  }

  protected function willFilterPage(array $tasks) {
    if ($this->groupBy == self::GROUP_PROJECT) {
      // We should only return project groups which the user can actually see.
      $project_phids = mpull($tasks, 'getGroupByProjectPHID');
      $projects = id(new PhabricatorProjectQuery())
        ->setViewer($this->getViewer())
        ->withPHIDs($project_phids)
        ->execute();
      $projects = mpull($projects, null, 'getPHID');

      foreach ($tasks as $key => $task) {
        if (!$task->getGroupByProjectPHID()) {
          // This task is either not in any projects, or only in projects
          // which we're ignoring because they're being queried for explicitly.
          continue;
        }

        if (empty($projects[$task->getGroupByProjectPHID()])) {
          unset($tasks[$key]);
        }
      }
    }

    return $tasks;
  }

  protected function didFilterPage(array $tasks) {
    $phids = mpull($tasks, 'getPHID');

    if ($this->needProjectPHIDs) {
      $edge_query = id(new PhabricatorEdgeQuery())
        ->withSourcePHIDs($phids)
        ->withEdgeTypes(
          array(
            PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
          ));
      $edge_query->execute();

      foreach ($tasks as $task) {
        $project_phids = $edge_query->getDestinationPHIDs(
          array($task->getPHID()));
        $task->attachProjectPHIDs($project_phids);
      }
    }

    if ($this->needSubscriberPHIDs) {
      $subscriber_sets = id(new PhabricatorSubscribersQuery())
        ->withObjectPHIDs($phids)
        ->execute();
      foreach ($tasks as $task) {
        $subscribers = idx($subscriber_sets, $task->getPHID(), array());
        $task->attachSubscriberPHIDs($subscribers);
      }
    }

    return $tasks;
  }

  private function buildTaskIDsWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->taskIDs) {
      return null;
    }

    return qsprintf(
      $conn,
      'task.id in (%Ld)',
      $this->taskIDs);
  }

  private function buildTaskPHIDsWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->taskPHIDs) {
      return null;
    }

    return qsprintf(
      $conn,
      'task.phid in (%Ls)',
      $this->taskPHIDs);
  }

  private function buildStatusWhereClause(AphrontDatabaseConnection $conn) {
    static $map = array(
      self::STATUS_RESOLVED   => ManiphestTaskStatus::STATUS_CLOSED_RESOLVED,
      self::STATUS_WONTFIX    => ManiphestTaskStatus::STATUS_CLOSED_WONTFIX,
      self::STATUS_INVALID    => ManiphestTaskStatus::STATUS_CLOSED_INVALID,
      self::STATUS_SPITE      => ManiphestTaskStatus::STATUS_CLOSED_SPITE,
      self::STATUS_DUPLICATE  => ManiphestTaskStatus::STATUS_CLOSED_DUPLICATE,
    );

    switch ($this->status) {
      case self::STATUS_ANY:
        return null;
      case self::STATUS_OPEN:
        return qsprintf(
          $conn,
          'task.status IN (%Ls)',
          ManiphestTaskStatus::getOpenStatusConstants());
      case self::STATUS_CLOSED:
        return qsprintf(
          $conn,
          'task.status IN (%Ls)',
          ManiphestTaskStatus::getClosedStatusConstants());
      default:
        $constant = idx($map, $this->status);
        if (!$constant) {
          throw new Exception("Unknown status query '{$this->status}'!");
        }
        return qsprintf(
          $conn,
          'task.status = %s',
          $constant);
    }
  }

  private function buildStatusesWhereClause(AphrontDatabaseConnection $conn) {
    if ($this->statuses) {
      return qsprintf(
        $conn,
        'task.status IN (%Ls)',
        $this->statuses);
    }
    return null;
  }

  private function buildAuthorWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->authorPHIDs) {
      return null;
    }

    return qsprintf(
      $conn,
      'task.authorPHID in (%Ls)',
      $this->authorPHIDs);
  }

  private function buildOwnerWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->ownerPHIDs) {
      if ($this->includeUnowned === null) {
        return null;
      } else if ($this->includeUnowned) {
        return qsprintf(
          $conn,
          'task.ownerPHID IS NULL');
      } else {
        return qsprintf(
          $conn,
          'task.ownerPHID IS NOT NULL');
      }
    }

    if ($this->includeUnowned) {
      return qsprintf(
        $conn,
        'task.ownerPHID IN (%Ls) OR task.ownerPHID IS NULL',
        $this->ownerPHIDs);
    } else {
      return qsprintf(
        $conn,
        'task.ownerPHID IN (%Ls)',
        $this->ownerPHIDs);
    }
  }

  private function buildFullTextWhereClause(AphrontDatabaseConnection $conn) {
    if (!strlen($this->fullTextSearch)) {
      return null;
    }

    // In doing a fulltext search, we first find all the PHIDs that match the
    // fulltext search, and then use that to limit the rest of the search
    $fulltext_query = id(new PhabricatorSavedQuery())
      ->setEngineClassName('PhabricatorSearchApplicationSearchEngine')
      ->setParameter('query', $this->fullTextSearch);

    // NOTE: Setting this to something larger than 2^53 will raise errors in
    // ElasticSearch, and billions of results won't fit in memory anyway.
    $fulltext_query->setParameter('limit', 100000);
    $fulltext_query->setParameter('type', ManiphestTaskPHIDType::TYPECONST);

    $engine = PhabricatorSearchEngineSelector::newSelector()->newEngine();
    $fulltext_results = $engine->executeSearch($fulltext_query);

    if (empty($fulltext_results)) {
      $fulltext_results = array(null);
    }

    return qsprintf(
      $conn,
      'task.phid IN (%Ls)',
      $fulltext_results);
  }

  private function buildDependenciesWhereClause(
    AphrontDatabaseConnection $conn) {

    if (!$this->shouldJoinBlockedTasks() &&
        !$this->shouldJoinBlockingTasks()) {
      return null;
    }

    $parts = array();
    if ($this->blockingTasks === true) {
      $parts[] = qsprintf(
        $conn,
        'blocking.dst IS NOT NULL AND blockingtask.status IN (%Ls)',
        ManiphestTaskStatus::getOpenStatusConstants());
    } else if ($this->blockingTasks === false) {
      $parts[] = qsprintf(
        $conn,
        'blocking.dst IS NULL OR blockingtask.status NOT IN (%Ls)',
        ManiphestTaskStatus::getOpenStatusConstants());
    }

    if ($this->blockedTasks === true) {
      $parts[] = qsprintf(
        $conn,
        'blocked.dst IS NOT NULL AND blockedtask.status IN (%Ls)',
        ManiphestTaskStatus::getOpenStatusConstants());
    } else if ($this->blockedTasks === false) {
      $parts[] = qsprintf(
        $conn,
        'blocked.dst IS NULL OR blockedtask.status NOT IN (%Ls)',
        ManiphestTaskStatus::getOpenStatusConstants());
    }

    return '('.implode(') OR (', $parts).')';
  }

  private function buildProjectWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->projectPHIDs && !$this->includeNoProject) {
      return null;
    }

    $parts = array();
    if ($this->projectPHIDs) {
      $parts[] = qsprintf(
        $conn,
        'project.dst in (%Ls)',
        $this->projectPHIDs);
    }
    if ($this->includeNoProject) {
      $parts[] = qsprintf(
        $conn,
        'project.dst IS NULL');
    }

    return '('.implode(') OR (', $parts).')';
  }

  private function buildAnyProjectWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->anyProjectPHIDs) {
      return null;
    }

    return qsprintf(
      $conn,
      'anyproject.dst IN (%Ls)',
      $this->anyProjectPHIDs);
  }

  private function buildAnyUserProjectWhereClause(
    AphrontDatabaseConnection $conn) {
    if (!$this->anyUserProjectPHIDs) {
      return null;
    }

    $projects = id(new PhabricatorProjectQuery())
      ->setViewer($this->getViewer())
      ->withMemberPHIDs($this->anyUserProjectPHIDs)
      ->execute();
    $any_user_project_phids = mpull($projects, 'getPHID');
    if (!$any_user_project_phids) {
      throw new PhabricatorEmptyQueryException();
    }

    return qsprintf(
      $conn,
      'anyproject.dst IN (%Ls)',
      $any_user_project_phids);
  }

  private function buildXProjectWhereClause(AphrontDatabaseConnection $conn) {
    if (!$this->xprojectPHIDs) {
      return null;
    }

    return qsprintf(
      $conn,
      'xproject.dst IS NULL');
  }

  private function buildJoinsClause(AphrontDatabaseConnection $conn_r) {
    $edge_table = PhabricatorEdgeConfig::TABLE_NAME_EDGE;

    $joins = array();

    if ($this->projectPHIDs || $this->includeNoProject) {
      $joins[] = qsprintf(
        $conn_r,
        '%Q JOIN %T project ON project.src = task.phid
          AND project.type = %d',
        ($this->includeNoProject ? 'LEFT' : ''),
        $edge_table,
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
    }

    if ($this->shouldJoinBlockingTasks()) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T blocking ON blocking.src = task.phid '.
        'AND blocking.type = %d '.
        'LEFT JOIN %T blockingtask ON blocking.dst = blockingtask.phid',
        $edge_table,
        ManiphestTaskDependedOnByTaskEdgeType::EDGECONST,
        id(new ManiphestTask())->getTableName());
    }
    if ($this->shouldJoinBlockedTasks()) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T blocked ON blocked.src = task.phid '.
        'AND blocked.type = %d '.
        'LEFT JOIN %T blockedtask ON blocked.dst = blockedtask.phid',
        $edge_table,
        ManiphestTaskDependsOnTaskEdgeType::EDGECONST,
        id(new ManiphestTask())->getTableName());
    }

    if ($this->anyProjectPHIDs || $this->anyUserProjectPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T anyproject ON anyproject.src = task.phid
          AND anyproject.type = %d',
        $edge_table,
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
    }

    if ($this->xprojectPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'LEFT JOIN %T xproject ON xproject.src = task.phid
          AND xproject.type = %d
          AND xproject.dst IN (%Ls)',
        $edge_table,
        PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
        $this->xprojectPHIDs);
    }

    if ($this->subscriberPHIDs) {
      $joins[] = qsprintf(
        $conn_r,
        'JOIN %T e_ccs ON e_ccs.src = task.phid '.
        'AND e_ccs.type = %s '.
        'AND e_ccs.dst in (%Ls)',
        PhabricatorEdgeConfig::TABLE_NAME_EDGE,
        PhabricatorObjectHasSubscriberEdgeType::EDGECONST,
        $this->subscriberPHIDs);
    }

    switch ($this->groupBy) {
      case self::GROUP_PROJECT:
        $ignore_group_phids = $this->getIgnoreGroupedProjectPHIDs();
        if ($ignore_group_phids) {
          $joins[] = qsprintf(
            $conn_r,
            'LEFT JOIN %T projectGroup ON task.phid = projectGroup.src
              AND projectGroup.type = %d
              AND projectGroup.dst NOT IN (%Ls)',
            $edge_table,
            PhabricatorProjectObjectHasProjectEdgeType::EDGECONST,
            $ignore_group_phids);
        } else {
          $joins[] = qsprintf(
            $conn_r,
            'LEFT JOIN %T projectGroup ON task.phid = projectGroup.src
              AND projectGroup.type = %d',
            $edge_table,
            PhabricatorProjectObjectHasProjectEdgeType::EDGECONST);
        }
        $joins[] = qsprintf(
          $conn_r,
          'LEFT JOIN %T projectGroupName
            ON projectGroup.dst = projectGroupName.indexedObjectPHID',
          id(new ManiphestNameIndex())->getTableName());
        break;
    }

    $joins[] = $this->buildApplicationSearchJoinClause($conn_r);

    return implode(' ', $joins);
  }

  protected function buildGroupClause(AphrontDatabaseConnection $conn_r) {
    $joined_multiple_rows = (count($this->projectPHIDs) > 1) ||
                            (count($this->anyProjectPHIDs) > 1) ||
                            $this->shouldJoinBlockingTasks() ||
                            $this->shouldJoinBlockedTasks() ||
                            ($this->getApplicationSearchMayJoinMultipleRows());

    $joined_project_name = ($this->groupBy == self::GROUP_PROJECT);

    // If we're joining multiple rows, we need to group the results by the
    // task IDs.
    if ($joined_multiple_rows) {
      if ($joined_project_name) {
        return 'GROUP BY task.phid, projectGroup.dst';
      } else {
        return 'GROUP BY task.phid';
      }
    } else {
      return '';
    }
  }

  /**
   * Return project PHIDs which we should ignore when grouping tasks by
   * project. For example, if a user issues a query like:
   *
   *   Tasks in all projects: Frontend, Bugs
   *
   * ...then we don't show "Frontend" or "Bugs" groups in the result set, since
   * they're meaningless as all results are in both groups.
   *
   * Similarly, for queries like:
   *
   *   Tasks in any projects: Public Relations
   *
   * ...we ignore the single project, as every result is in that project. (In
   * the case that there are several "any" projects, we do not ignore them.)
   *
   * @return list<phid> Project PHIDs which should be ignored in query
   *                    construction.
   */
  private function getIgnoreGroupedProjectPHIDs() {
    $phids = array();

    if ($this->projectPHIDs) {
      $phids[] = $this->projectPHIDs;
    }

    if (count($this->anyProjectPHIDs) == 1) {
      $phids[] = $this->anyProjectPHIDs;
    }

    // Maybe we should also exclude the "excludeProjectPHIDs"? It won't
    // impact the results, but we might end up with a better query plan.
    // Investigate this on real data? This is likely very rare.

    return array_mergev($phids);
  }

  protected function getResultCursor($result) {
    $id = $result->getID();

    if ($this->groupBy == self::GROUP_PROJECT) {
      return rtrim($id.'.'.$result->getGroupByProjectPHID(), '.');;
    }

    return $id;
  }

  public function getOrderableColumns() {
    return parent::getOrderableColumns() + array(
      'priority' => array(
        'table' => 'task',
        'column' => 'priority',
        'type' => 'int',
      ),
      'owner' => array(
        'table' => 'task',
        'column' => 'ownerOrdering',
        'null' => 'head',
        'reverse' => true,
        'type' => 'string',
      ),
      'status' => array(
        'table' => 'task',
        'column' => 'status',
        'type' => 'string',
        'reverse' => true,
      ),
      'project' => array(
        'table' => 'projectGroupName',
        'column' => 'indexedObjectName',
        'type' => 'string',
        'null' => 'head',
        'reverse' => true,
      ),
      'title' => array(
        'table' => 'task',
        'column' => 'title',
        'type' => 'string',
        'reverse' => true,
      ),
      'subpriority' => array(
        'table' => 'task',
        'column' => 'subpriority',
        'type' => 'float',
      ),
      'updated' => array(
        'table' => 'task',
        'column' => 'dateModified',
        'type' => 'int',
      ),
    );
  }

  protected function getPagingValueMap($cursor, array $keys) {
    $cursor_parts = explode('.', $cursor, 2);
    $task_id = $cursor_parts[0];
    $group_id = idx($cursor_parts, 1);

    $task = $this->loadCursorObject($task_id);

    $map = array(
      'id' => $task->getID(),
      'priority' => $task->getPriority(),
      'subpriority' => $task->getSubpriority(),
      'owner' => $task->getOwnerOrdering(),
      'status' => $task->getStatus(),
      'title' => $task->getTitle(),
      'updated' => $task->getDateModified(),
    );

    foreach ($keys as $key) {
      switch ($key) {
        case 'project':
          $value = null;
          if ($group_id) {
            $paging_projects = id(new PhabricatorProjectQuery())
              ->setViewer($this->getViewer())
              ->withPHIDs(array($group_id))
              ->execute();
            if ($paging_projects) {
              $value = head($paging_projects)->getName();
            }
          }
          $map[$key] = $value;
          break;
      }
    }

    foreach ($keys as $key) {
      if ($this->isCustomFieldOrderKey($key)) {
        $map += $this->getPagingValueMapForCustomFields($task);
        break;
      }
    }

    return $map;
  }

  protected function getPrimaryTableAlias() {
    return 'task';
  }

  public function getQueryApplicationClass() {
    return 'PhabricatorManiphestApplication';
  }

}
