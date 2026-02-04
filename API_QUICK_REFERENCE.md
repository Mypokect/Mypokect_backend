# 🚀 API QUICK REFERENCE - Goal Contributions

## Base URL
```
http://localhost:8000/api
```

## Authentication
```
Header: Authorization: Bearer {token}
```

---

## Goal Contributions Endpoints

### 1️⃣ LIST CONTRIBUTIONS
```bash
curl -X GET "http://localhost:8000/api/goal-contributions/1" \
  -H "Authorization: Bearer {token}"
```
**Status:** 200 | **Type:** GET

---

### 2️⃣ CREATE CONTRIBUTION
```bash
curl -X POST "http://localhost:8000/api/goal-contributions" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "goal_id": 1,
    "amount": 500000,
    "description": "Primer abono"
  }'
```
**Status:** 201 | **Type:** POST

---

### 3️⃣ DELETE CONTRIBUTION
```bash
curl -X DELETE "http://localhost:8000/api/goal-contributions/1" \
  -H "Authorization: Bearer {token}"
```
**Status:** 200 | **Type:** DELETE

---

### 4️⃣ GET STATISTICS
```bash
curl -X GET "http://localhost:8000/api/goal-contributions/1/stats" \
  -H "Authorization: Bearer {token}"
```
**Status:** 200 | **Type:** GET

---

## Unified Transactions Endpoint

### 5️⃣ GET ALL TRANSACTIONS (Mixed)
```bash
# All transactions
curl -X GET "http://localhost:8000/api/transactions/unified" \
  -H "Authorization: Bearer {token}"

# Only expenses and contributions
curl -X GET "http://localhost:8000/api/transactions/unified?type=expense,contribution" \
  -H "Authorization: Bearer {token}"

# By date range
curl -X GET "http://localhost:8000/api/transactions/unified?start_date=2026-01-01&end_date=2026-01-31" \
  -H "Authorization: Bearer {token}"

# By goal (contributions only)
curl -X GET "http://localhost:8000/api/transactions/unified?type=contribution&goal_id=1" \
  -H "Authorization: Bearer {token}"

# With pagination
curl -X GET "http://localhost:8000/api/transactions/unified?page=2&per_page=50" \
  -H "Authorization: Bearer {token}"
```
**Status:** 200 | **Type:** GET

---

## Query Parameters

### For `/transactions/unified`

| Param | Type | Values | Example |
|-------|------|--------|---------|
| `type` | string | `expense,income,contribution` | `?type=expense,contribution` |
| `start_date` | date | YYYY-MM-DD | `?start_date=2026-01-01` |
| `end_date` | date | YYYY-MM-DD | `?end_date=2026-01-31` |
| `goal_id` | integer | Any goal ID | `?goal_id=1` |
| `page` | integer | >= 1 | `?page=2` |
| `per_page` | integer | 10, 50, 100 | `?per_page=50` |

---

## Response Examples

### ✅ Success - Create Contribution (201)
```json
{
  "message": "Contribution created successfully",
  "data": {
    "id": "gc_1",
    "goal_id": 1,
    "goal_name": "Viaje a París",
    "amount": 500000,
    "description": "Primer abono",
    "date": "2026-01-27T15:30:00Z",
    "created_at": "2026-01-27T15:30:00Z",
    "updated_at": "2026-01-27T15:30:00Z"
  }
}
```

### ✅ Success - List Contributions (200)
```json
{
  "data": [
    {
      "id": "gc_1",
      "goal_id": 1,
      "goal_name": "Viaje a París",
      "amount": 500000,
      "description": "Primer abono",
      "date": "2026-01-27T15:30:00Z",
      "created_at": "2026-01-27T15:30:00Z",
      "updated_at": "2026-01-27T15:30:00Z"
    }
  ],
  "total": 1
}
```

### ✅ Success - Statistics (200)
```json
{
  "total_contributions": 3,
  "total_amount": 1500000,
  "average_contribution": 500000,
  "largest_contribution": 600000,
  "smallest_contribution": 300000,
  "last_contribution_date": "2026-01-28T10:00:00Z",
  "percentage_of_goal": 30
}
```

### ✅ Success - Unified Transactions (200)
```json
{
  "data": [
    {
      "id": "m_1",
      "type": "expense",
      "type_badge": "Gasto",
      "amount": 50000,
      "description": "Lunch",
      "category": "Comida",
      "goal_name": null,
      "date": "2026-01-27T12:00:00Z",
      "payment_method": "cash",
      "source": "movement"
    },
    {
      "id": "gc_1",
      "type": "contribution",
      "type_badge": "Abono",
      "amount": 500000,
      "description": "Primer abono",
      "category": null,
      "goal_name": "Viaje a París",
      "date": "2026-01-27T14:30:00Z",
      "payment_method": null,
      "source": "goal_contribution"
    }
  ],
  "pagination": {
    "total": 2,
    "per_page": 50,
    "current_page": 1,
    "last_page": 1,
    "from": 1,
    "to": 2
  }
}
```

### ❌ Error - Unauthorized (401)
```json
{
  "error": "Unauthorized"
}
```

### ❌ Error - Validation (422)
```json
{
  "error": "Validation failed",
  "messages": {
    "goal_id": ["The goal_id field is required"],
    "amount": ["The amount must be at least 0.01"]
  }
}
```

### ❌ Error - Not Found (404)
```json
{
  "error": "Goal not found"
}
```

---

## Testing with Postman

1. Set Authorization
   - Type: Bearer Token
   - Token: {your_token}

2. Test each endpoint:
   - Create: POST /goal-contributions
   - List: GET /goal-contributions/1
   - Stats: GET /goal-contributions/1/stats
   - Delete: DELETE /goal-contributions/1
   - Unified: GET /transactions/unified

3. Try different query params:
   - `?type=contribution`
   - `?type=expense,income`
   - `?start_date=2026-01-01&end_date=2026-01-31`
   - `?page=2&per_page=50`

---

## HTTP Status Codes

| Code | Meaning | When |
|------|---------|------|
| 200 | OK | Successful GET, DELETE |
| 201 | Created | Successful POST |
| 400 | Bad Request | Invalid parameters |
| 401 | Unauthorized | No auth token |
| 404 | Not Found | Resource doesn't exist |
| 422 | Validation Error | Validation failed |
| 500 | Server Error | Database/server issue |

---

**Last Updated:** 28 Enero 2026
**API Version:** 1.0.0
