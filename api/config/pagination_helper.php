<?php
/**
 * Pagination and Search Helper Functions
 * Provides standardized pagination and search functionality for all API endpoints
 */

/**
 * Parse pagination parameters from input
 * @param array $input Input data from request
 * @return array Pagination parameters
 */
function getPaginationParams($input) {
    $page = (int) ($input['page'] ?? 1);
    $limit = (int) ($input['limit'] ?? 5);
    $offset = ($page - 1) * $limit;
    
    // Ensure minimum values
    $page = max(1, $page);
    $limit = max(1, min(100, $limit)); // Max 100 items per page
    $offset = max(0, $offset);
    
    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'useLimit' => $limit > 0
    ];
}

/**
 * Build search conditions for SQL WHERE clause
 * @param string $search Search term
 * @param array $searchFields Array of field names to search in
 * @param string $tableAlias Table alias (optional)
 * @return array ['sql' => string, 'params' => array]
 */
function buildSearchConditions($search, $searchFields, $tableAlias = '') {
    $search = trim($search);
    
    // ถ้าไม่มีคำค้นหาหรือไม่มี search fields ให้ return ค่าว่าง
    if (empty($search) || empty($searchFields)) {
        return ['sql' => '', 'params' => []];
    }
    
    // ถ้าคำค้นหาสั้นเกินไป (น้อยกว่า 1 ตัวอักษร) ให้ return ค่าว่าง
    if (strlen($search) < 1) {
        return ['sql' => '', 'params' => []];
    }
    
    $prefix = $tableAlias ? $tableAlias . '.' : '';
    $conditions = [];
    $params = [];
    
    foreach ($searchFields as $field) {
        $conditions[] = "{$prefix}{$field} LIKE :search";
    }
    
    $sql = ' AND (' . implode(' OR ', $conditions) . ')';
    $params[':search'] = "%{$search}%";
    
    return ['sql' => $sql, 'params' => $params];
}

/**
 * Get total count for pagination
 * @param PDO $dbh Database handle
 * @param string $countSql Count SQL query
 * @param array $params Parameters for the query
 * @return int Total count
 */
function getTotalCount($dbh, $countSql, $params = []) {
    try {
        $stmt = $dbh->prepare($countSql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Build pagination response
 * @param int $totalItems Total number of items
 * @param int $page Current page
 * @param int $limit Items per page
 * @return array Pagination information
 */
function buildPaginationResponse($totalItems, $page, $limit) {
    $totalPages = $limit > 0 ? ceil($totalItems / $limit) : 1;
    
    return [
        'totalItems' => $totalItems,
        'totalPages' => $totalPages,
        'currentPage' => $page,
        'limit' => $limit,
        'hasNextPage' => $page < $totalPages,
        'hasPrevPage' => $page > 1
    ];
}

/**
 * Apply pagination to SQL query
 * @param string $sql Base SQL query
 * @param bool $useLimit Whether to apply limit
 * @return string SQL with LIMIT clause
 */
function applyPagination($sql, $useLimit = true) {
    if ($useLimit) {
        $sql .= " LIMIT :limit OFFSET :offset";
    }
    return $sql;
}

/**
 * Bind pagination parameters to statement
 * @param PDOStatement $stmt PDO statement
 * @param array $paginationParams Pagination parameters
 * @param array $additionalParams Additional parameters to bind
 */
function bindPaginationParams($stmt, $paginationParams, $additionalParams = []) {
    // Bind additional parameters first
    foreach ($additionalParams as $key => $value) {
        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    // Bind pagination parameters
    if ($paginationParams['useLimit']) {
        $stmt->bindValue(':limit', $paginationParams['limit'], PDO::PARAM_INT);
        $stmt->bindValue(':offset', $paginationParams['offset'], PDO::PARAM_INT);
    }
}

/**
 * Standard API response format
 * @param string $status Status (success/error)
 * @param mixed $data Response data
 * @param array $pagination Pagination info (optional)
 * @param string $message Message (optional)
 * @return array Response array
 */
function buildApiResponse($status, $data = null, $pagination = null, $message = '') {
    $response = ['status' => $status];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    if ($pagination !== null) {
        $response['pagination'] = $pagination;
    }
    
    if (!empty($message)) {
        $response['message'] = $message;
    }
    
    return $response;
}

/**
 * Complete pagination and search handler
 * @param PDO $dbh Database handle
 * @param array $input Request input
 * @param string $baseSql Base SQL query (without WHERE, ORDER BY, LIMIT)
 * @param string $countSql Count SQL query
 * @param array $searchFields Fields to search in
 * @param string $orderBy ORDER BY clause
 * @param string $whereClause Additional WHERE conditions
 * @param array $additionalParams Additional parameters
 * @return array Complete response with data and pagination
 */
function handlePaginatedSearch($dbh, $input, $baseSql, $countSql, $searchFields = [], $orderBy = '', $whereClause = 'WHERE 1=1', $additionalParams = []) {
    try {
        // Get pagination parameters
        $pagination = getPaginationParams($input);
        $search = trim($input['search'] ?? '');
        
        // Build search conditions
        $searchConditions = buildSearchConditions($search, $searchFields);
        
        // Combine all parameters
        $allParams = array_merge($additionalParams, $searchConditions['params']);
        
        // Build complete WHERE clause
        $completeWhere = $whereClause . $searchConditions['sql'];
        
        // Get total count
        $fullCountSql = $countSql . ' ' . $completeWhere;
        $totalItems = getTotalCount($dbh, $fullCountSql, $allParams);
        
        // Build main query
        $fullSql = $baseSql . ' ' . $completeWhere;
        if (!empty($orderBy)) {
            $fullSql .= ' ' . $orderBy;
        }
        $fullSql = applyPagination($fullSql, $pagination['useLimit']);
        
        // Execute main query
        $stmt = $dbh->prepare($fullSql);
        bindPaginationParams($stmt, $pagination, $allParams);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build response
        $paginationInfo = buildPaginationResponse($totalItems, $pagination['page'], $pagination['limit']);
        
        return buildApiResponse('success', $results, $paginationInfo);
        
    } catch (Exception $e) {
        return buildApiResponse('error', null, null, $e->getMessage());
    }
}
?>
