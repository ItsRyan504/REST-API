# GradeTrack REST API

This project is an `ADET` activity that demonstrates how a custom REST API works with `2 clients`.

It includes:

- A `PHP + MySQL` REST API
- A browser-based client built with `HTML/CSS/JavaScript`
- A second client built with `C`

## Project Overview

The system manages student records and subject grades for a simple school grading setup. It supports:

- User registration
- User login and token-based authentication
- Student CRUD operations
- Subject CRUD operations
- JSON responses for all endpoints
- Multi-status API responses such as `200`, `201`, `400`, `401`, `404`, `405`, and `409`
- GWA calculation and academic remarks

## How This Meets The Activity Requirements

### Part 1: Server-Side API Enhancement

The API satisfies the required features:

- Accepts at least 2 parameters
- Returns JSON responses
- Implements meaningful system logic

Examples:

- `POST /api/register` accepts `full_name`, `username`, and `password`
- `POST /api/students` accepts `name` and `year_level`
- `POST /api/students/{id}/subjects` accepts `subject_name` and `grade`

Expanded logic included in the API:

- User registration system
- Login validation
- CRUD for students
- CRUD for subjects
- GWA computation
- Status-based responses with detailed messages

### Part 2: Two Client Applications

This activity can be demonstrated using these two clients:

1. `Client 1: Web Browser`
   The included frontend in `index.html` and `js/app.js` consumes the API using the Fetch API.

2. `Client 2: C CLI`
   The file `c_client.c` consumes the same API from the command line.

## Project Files

- `api.php` - main REST API
- `db.php` - database connection settings
- `database.sql` - database schema
- `sample_data.sql` - demo seed data
- `index.html` - browser client UI
- `js/app.js` - browser client logic
- `css/style.css` - browser client styling
- `c_client.c` - simple C command-line client with buffered menu input
- `python_client.py` - optional Python reference client
- `.htaccess` - URL rewrite for `/api/...` routes

## Database Setup

1. Create/import the database using `database.sql`
2. Import demo records using `sample_data.sql`
3. Make sure MySQL is running
4. Update `db.php` if your database username, password, or port is different

Default database settings in `db.php`:

- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_NAME=gradetrack`
- `DB_USER=root`
- `DB_PASS=` (empty by default)

## Running The Project

This project is designed to run in a local PHP/XAMPP environment.

1. Place the folder inside your web server directory such as `htdocs`
2. Start `Apache` and `MySQL`
3. Open the project in the browser

Example:

```text
http://localhost/REST-API/
```

Base API URL using rewrite:

```text
http://localhost/REST-API/api
```

Direct API file URL:

```text
http://localhost/REST-API/api.php
```

The `.htaccess` file rewrites `/api/...` requests to `api.php/...`, and the C client uses the direct `api.php` URL by default.

## Demo Account

Seeded demo credentials from `sample_data.sql`:

- Username: `teacher_demo`
- Password: `teacher123`

## Authentication

Protected routes require a Bearer token.

Typical flow:

1. Register or log in
2. Receive a token from `/api/login`
3. Send `Authorization: Bearer YOUR_TOKEN`
4. Access protected endpoints such as `/api/students`

## Available Endpoints

### Public Endpoints

- `POST /api/register` - create a user account
- `POST /api/login` - log in and receive token
- `POST /api/logout` - remove current session token

### Protected Endpoints

- `GET /api/me` - get current logged-in user
- `GET /api/students` - list all students
- `POST /api/students` - create a student
- `GET /api/students/{id}` - get one student
- `PUT /api/students/{id}` - update student info
- `DELETE /api/students/{id}` - delete student
- `GET /api/students/{id}/gwa` - get GWA summary
- `POST /api/students/{id}/subjects` - add subject grade
- `PUT /api/students/{id}/subjects/{subjectName}` - update subject grade
- `DELETE /api/students/{id}/subjects/{subjectName}` - delete subject

## C Client Setup

Compile and run the C client from the project folder.

Example using MinGW on Windows:

```bash
gcc c_client.c -o c_client.exe -lwinhttp
.\c_client.exe
```

Default API base URL:

```text
http://localhost/REST-API/api.php
```

If your local URL is different, set `API_BASE_URL` first:

```bash
set API_BASE_URL=http://localhost/REST-API/api.php
gcc c_client.c -o c_client.exe -lwinhttp
.\c_client.exe
```

The C client can:

- Register a user
- Log in
- View the logged-in account
- List students
- Add a student
- View student GWA
- Handle API and connection errors with clear messages
- Read menu choices and text input safely with buffered input

If you are using the Microsoft compiler instead of MinGW, compile it like this:

```bash
cl c_client.c /link winhttp.lib
```

## Example Requests For Testing

### Register

```bash
curl -X POST http://localhost/REST-API/api/register ^
  -H "Content-Type: application/json" ^
  -d "{\"full_name\":\"Jane Teacher\",\"username\":\"jane_teacher\",\"password\":\"secret123\"}"
```

### Login

```bash
curl -X POST http://localhost/REST-API/api/login ^
  -H "Content-Type: application/json" ^
  -d "{\"username\":\"teacher_demo\",\"password\":\"teacher123\"}"
```

Example successful response:

```json
{
  "success": true,
  "message": "Login successful!",
  "token": "YOUR_TOKEN_HERE",
  "user": {
    "id": 1,
    "username": "teacher_demo",
    "full_name": "Demo Teacher",
    "role": "teacher",
    "created_at": 1776038400
  }
}
```

### Get All Students

```bash
curl http://localhost/REST-API/api/students ^
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

### Add A Student

```bash
curl -X POST http://localhost/REST-API/api/students ^
  -H "Content-Type: application/json" ^
  -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
  -d "{\"name\":\"Mark Santos\",\"year_level\":\"2nd Year\"}"
```

### Add A Subject

```bash
curl -X POST http://localhost/REST-API/api/students/1/subjects ^
  -H "Content-Type: application/json" ^
  -H "Authorization: Bearer YOUR_TOKEN_HERE" ^
  -d "{\"subject_name\":\"Physics\",\"grade\":1.75}"
```

### Get Student GWA

```bash
curl http://localhost/REST-API/api/students/1/gwa ^
  -H "Authorization: Bearer YOUR_TOKEN_HERE"
```

## Client 1: Browser Frontend

The included web client can:

- Register a teacher account
- Log in
- View all students
- Search students
- Add, edit, and delete students
- Add, edit, and delete subjects
- Display GWA and remarks

It uses `fetch()` in `js/app.js` to call the REST API and display the results in the browser.

## Client 2: C CLI

The second client demonstrates that the API is not limited to the browser interface. The C app sends HTTP requests, reads JSON responses, displays the returned data clearly, and shows friendly error messages when requests fail. Its menu flow is intentionally similar to the Python version, including the `Choose an action` prompt and buffered console input handling.

## Grade Rules

Accepted grade values in the API are:

`1.00, 1.25, 1.50, 1.75, 2.00, 2.25, 2.50, 2.75, 3.00, 5.00`

The API also assigns remarks such as:

- `Summa Cum Laude`
- `Magna Cum Laude`
- `Cum Laude`
- `Passed`
- `Failed`

## Notes

- All API responses are returned as JSON
- Invalid requests return helpful error messages
- Unauthorized requests return `401`
- Duplicate usernames or duplicate subjects return `409`
- Unknown routes return `404`

## Summary

This project demonstrates a complete REST API with authentication, CRUD operations, GWA processing, and JSON-based communication. It also shows how one API can be used by `2 clients`: a custom browser frontend and a C command-line client.
