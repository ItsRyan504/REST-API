#define _CRT_SECURE_NO_WARNINGS

#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#define DEFAULT_BASE_URL "http://localhost/REST-API/api.php"
#define BASE_URL_SIZE 512
#define TOKEN_SIZE 256
#define NAME_SIZE 256
#define INPUT_SIZE 256
#define RESPONSE_SIZE 8192

typedef struct {
    char base_url[BASE_URL_SIZE];
    char token[TOKEN_SIZE];
    char display_name[NAME_SIZE];
    int is_logged_in;
} SchoolAPIClient;

static void clear_terminal(void)
{
    system("cls");
}

static void trim_newline(char *text)
{
    size_t len;

    if (!text) {
        return;
    }

    len = strlen(text);
    while (len > 0 && (text[len - 1] == '\n' || text[len - 1] == '\r')) {
        text[len - 1] = '\0';
        len--;
    }
}

static void flush_stdin_buffer(void)
{
    int ch;
    while ((ch = getchar()) != '\n' && ch != EOF) {
    }
}

static int read_line(const char *prompt, char *buffer, size_t size)
{
    if (prompt) {
        printf("%s", prompt);
    }

    if (!fgets(buffer, (int) size, stdin)) {
        return 0;
    }

    if (!strchr(buffer, '\n')) {
        flush_stdin_buffer();
    }

    trim_newline(buffer);
    return 1;
}

static void pause_for_enter(void)
{
    char dummy[8];
    read_line("\nPress Enter to proceed...", dummy, sizeof(dummy));
}

static void safe_copy(char *destination, size_t size, const char *source)
{
    if (!destination || size == 0) {
        return;
    }

    if (!source) {
        source = "";
    }

    snprintf(destination, size, "%s", source);
}

static void init_client(SchoolAPIClient *client)
{
    const char *env_base_url = getenv("API_BASE_URL");
    size_t len;

    safe_copy(client->base_url, sizeof(client->base_url), env_base_url && *env_base_url ? env_base_url : DEFAULT_BASE_URL);
    client->token[0] = '\0';
    client->display_name[0] = '\0';
    client->is_logged_in = 0;

    len = strlen(client->base_url);
    while (len > 0 && client->base_url[len - 1] == '/') {
        client->base_url[len - 1] = '\0';
        len--;
    }
}

static void print_title(const char *title)
{
    printf("\n========================================================\n");
    printf("%s\n", title);
    printf("========================================================\n");
}

static void render_header(const SchoolAPIClient *client)
{
    clear_terminal();
    print_title("GradeTrack - Simple C Client");
    printf("API Base URL: %s\n", client->base_url);
    printf("Logged in as: %s\n", client->is_logged_in ? client->display_name : "Guest");
}

static int read_command_output(const char *command, char *output, size_t size)
{
    FILE *pipe;
    size_t total = 0;

    if (!command || !output || size == 0) {
        return 0;
    }

    output[0] = '\0';
    pipe = _popen(command, "r");
    if (!pipe) {
        return 0;
    }

    while (!feof(pipe) && total + 1 < size) {
        size_t read_bytes = fread(output + total, 1, size - total - 1, pipe);
        if (read_bytes == 0) {
            break;
        }
        total += read_bytes;
    }

    output[total] = '\0';
    _pclose(pipe);
    return 1;
}

static int write_temp_payload(const char *payload, char *path, size_t size)
{
    FILE *file;
    char tmp_name[L_tmpnam];

    if (!payload || !path || size == 0) {
        return 0;
    }

    if (!tmpnam(tmp_name)) {
        return 0;
    }

    if (strlen(tmp_name) + 1 > size) {
        return 0;
    }

    safe_copy(path, size, tmp_name);

    file = fopen(path, "wb");
    if (!file) {
        return 0;
    }

    fwrite(payload, 1, strlen(payload), file);
    fclose(file);
    return 1;
}

static char *json_escape_string(const char *input)
{
    size_t len = strlen(input);
    char *escaped = (char *) malloc((len * 2) + 1);
    size_t out = 0;
    size_t i;

    if (!escaped) {
        return NULL;
    }

    for (i = 0; i < len; i++) {
        switch (input[i]) {
            case '"':
            case '\\':
                escaped[out++] = '\\';
                escaped[out++] = input[i];
                break;
            case '\n':
                escaped[out++] = '\\';
                escaped[out++] = 'n';
                break;
            case '\r':
                escaped[out++] = '\\';
                escaped[out++] = 'r';
                break;
            case '\t':
                escaped[out++] = '\\';
                escaped[out++] = 't';
                break;
            default:
                escaped[out++] = input[i];
                break;
        }
    }

    escaped[out] = '\0';
    return escaped;
}

static const char *skip_ws(const char *text)
{
    while (text && *text && isspace((unsigned char) *text)) {
        text++;
    }
    return text;
}

static const char *find_matching_pair(const char *start, char open_ch, char close_ch)
{
    int depth = 0;
    int in_string = 0;
    int escaped = 0;
    const char *cursor;

    if (!start || *start != open_ch) {
        return NULL;
    }

    for (cursor = start; *cursor; cursor++) {
        char current = *cursor;

        if (in_string) {
            if (escaped) {
                escaped = 0;
            } else if (current == '\\') {
                escaped = 1;
            } else if (current == '"') {
                in_string = 0;
            }
            continue;
        }

        if (current == '"') {
            in_string = 1;
            continue;
        }

        if (current == open_ch) {
            depth++;
        } else if (current == close_ch) {
            depth--;
            if (depth == 0) {
                return cursor;
            }
        }
    }

    return NULL;
}

static char *extract_container(const char *json, const char *key, char open_ch, char close_ch)
{
    char pattern[64];
    const char *found;
    const char *value;
    const char *end;
    size_t length;
    char *copy;

    if (!json || !key) {
        return NULL;
    }

    snprintf(pattern, sizeof(pattern), "\"%s\"", key);
    found = strstr(json, pattern);
    if (!found) {
        return NULL;
    }

    value = strchr(found + strlen(pattern), ':');
    if (!value) {
        return NULL;
    }

    value = skip_ws(value + 1);
    if (!value || *value != open_ch) {
        return NULL;
    }

    end = find_matching_pair(value, open_ch, close_ch);
    if (!end) {
        return NULL;
    }

    length = (size_t) (end - value) + 1;
    copy = (char *) malloc(length + 1);
    if (!copy) {
        return NULL;
    }

    memcpy(copy, value, length);
    copy[length] = '\0';
    return copy;
}

static int extract_json_string(const char *json, const char *key, char *buffer, size_t size)
{
    char pattern[64];
    const char *found;
    const char *start;
    const char *cursor;
    size_t out = 0;
    int escaped = 0;

    if (!json || !key) {
        return 0;
    }

    snprintf(pattern, sizeof(pattern), "\"%s\"", key);
    found = strstr(json, pattern);
    if (!found) {
        return 0;
    }

    start = strchr(found + strlen(pattern), ':');
    if (!start) {
        return 0;
    }

    start = skip_ws(start + 1);
    if (!start || *start != '"') {
        return 0;
    }

    cursor = start + 1;
    while (*cursor && out + 1 < size) {
        if (escaped) {
            switch (*cursor) {
                case '"':
                    buffer[out++] = '"';
                    break;
                case '\\':
                    buffer[out++] = '\\';
                    break;
                case 'n':
                    buffer[out++] = '\n';
                    break;
                case 'r':
                    buffer[out++] = '\r';
                    break;
                case 't':
                    buffer[out++] = '\t';
                    break;
                default:
                    buffer[out++] = *cursor;
                    break;
            }
            escaped = 0;
        } else if (*cursor == '\\') {
            escaped = 1;
        } else if (*cursor == '"') {
            buffer[out] = '\0';
            return 1;
        } else {
            buffer[out++] = *cursor;
        }
        cursor++;
    }

    buffer[out] = '\0';
    return 0;
}

static int extract_json_int(const char *json, const char *key, int *value)
{
    char pattern[64];
    const char *found;
    const char *start;
    char *end = NULL;
    long parsed;

    if (!json || !key) {
        return 0;
    }

    snprintf(pattern, sizeof(pattern), "\"%s\"", key);
    found = strstr(json, pattern);
    if (!found) {
        return 0;
    }

    start = strchr(found + strlen(pattern), ':');
    if (!start) {
        return 0;
    }

    parsed = strtol(skip_ws(start + 1), &end, 10);
    if (!end || end == start) {
        return 0;
    }

    *value = (int) parsed;
    return 1;
}

static int extract_json_double(const char *json, const char *key, double *value, int *is_null)
{
    char pattern[64];
    const char *found;
    const char *start;
    char *end = NULL;

    if (!json || !key) {
        return 0;
    }

    snprintf(pattern, sizeof(pattern), "\"%s\"", key);
    found = strstr(json, pattern);
    if (!found) {
        return 0;
    }

    start = strchr(found + strlen(pattern), ':');
    if (!start) {
        return 0;
    }

    start = skip_ws(start + 1);
    if (strncmp(start, "null", 4) == 0) {
        *is_null = 1;
        *value = 0.0;
        return 1;
    }

    *value = strtod(start, &end);
    if (!end || end == start) {
        return 0;
    }

    *is_null = 0;
    return 1;
}

static int print_account_details(const char *json)
{
    char *data = extract_container(json, "data", '{', '}');
    char full_name[NAME_SIZE] = "";
    char username[INPUT_SIZE] = "";
    char role[INPUT_SIZE] = "";
    int id = 0;

    if (!data) {
        return 0;
    }

    extract_json_int(data, "id", &id);
    extract_json_string(data, "full_name", full_name, sizeof(full_name));
    extract_json_string(data, "username", username, sizeof(username));
    extract_json_string(data, "role", role, sizeof(role));

    printf("\nID: %d\n", id);
    printf("Name: %s\n", full_name[0] ? full_name : "N/A");
    printf("Username: %s\n", username[0] ? username : "N/A");
    printf("Role: %s\n", role[0] ? role : "N/A");

    free(data);
    return 1;
}

static int print_students(const char *json)
{
    char *data = extract_container(json, "data", '[', ']');
    const char *cursor;
    int printed = 0;

    if (!data) {
        return 0;
    }

    cursor = data + 1;
    while (*cursor) {
        const char *end;
        size_t length;
        char *student;
        char name[NAME_SIZE] = "";
        char year_level[INPUT_SIZE] = "";
        char remarks[INPUT_SIZE] = "";
        double gwa = 0.0;
        int gwa_is_null = 1;
        int id = 0;

        while (*cursor && (isspace((unsigned char) *cursor) || *cursor == ',')) {
            cursor++;
        }

        if (*cursor == ']') {
            break;
        }

        if (*cursor != '{') {
            cursor++;
            continue;
        }

        end = find_matching_pair(cursor, '{', '}');
        if (!end) {
            break;
        }

        length = (size_t) (end - cursor) + 1;
        student = (char *) malloc(length + 1);
        if (!student) {
            break;
        }

        memcpy(student, cursor, length);
        student[length] = '\0';

        extract_json_int(student, "id", &id);
        extract_json_string(student, "name", name, sizeof(name));
        extract_json_string(student, "year_level", year_level, sizeof(year_level));
        extract_json_string(student, "remarks", remarks, sizeof(remarks));
        extract_json_double(student, "gwa", &gwa, &gwa_is_null);

        printf("ID: %d | Name: %s | Year: %s | GWA: ",
            id,
            name[0] ? name : "N/A",
            year_level[0] ? year_level : "N/A"
        );

        if (gwa_is_null) {
            printf("N/A");
        } else {
            printf("%.2f", gwa);
        }

        printf(" | Remarks: %s\n", remarks[0] ? remarks : "N/A");

        printed++;
        free(student);
        cursor = end + 1;
    }

    if (!printed) {
        printf("\nNo students found.\n");
    }

    free(data);
    return 1;
}

static int print_gwa_summary(const char *json)
{
    char *data = extract_container(json, "data", '{', '}');
    char name[NAME_SIZE] = "";
    char year_level[INPUT_SIZE] = "";
    char remarks[INPUT_SIZE] = "";
    double gwa = 0.0;
    int gwa_is_null = 1;
    int total_subjects = 0;

    if (!data) {
        return 0;
    }

    extract_json_string(data, "name", name, sizeof(name));
    extract_json_string(data, "year_level", year_level, sizeof(year_level));
    extract_json_string(data, "remarks", remarks, sizeof(remarks));
    extract_json_double(data, "gwa", &gwa, &gwa_is_null);
    extract_json_int(data, "total_subjects", &total_subjects);

    printf("\nStudent: %s\n", name[0] ? name : "N/A");
    printf("Year Level: %s\n", year_level[0] ? year_level : "N/A");
    printf("GWA: ");
    if (gwa_is_null) {
        printf("N/A\n");
    } else {
        printf("%.4f\n", gwa);
    }
    printf("Remarks: %s\n", remarks[0] ? remarks : "N/A");
    printf("Total Subjects: %d\n", total_subjects);

    free(data);
    return 1;
}

static void print_message(const char *json, const char *fallback)
{
    char message[INPUT_SIZE * 2] = "";

    if (extract_json_string(json, "message", message, sizeof(message))) {
        printf("\n%s\n", message);
        return;
    }

    if (fallback && *fallback) {
        printf("\n%s\n", fallback);
        return;
    }
}

static void run_curl_request(const char *command, char *response, size_t size)
{
    if (!read_command_output(command, response, size)) {
        snprintf(response, size, "{\"message\":\"Failed to run curl.\"}");
    }
}

static void handle_register(SchoolAPIClient *client)
{
    char full_name[NAME_SIZE];
    char username[INPUT_SIZE];
    char password[INPUT_SIZE];
    char *escaped_name = NULL;
    char *escaped_username = NULL;
    char *escaped_password = NULL;
    char payload[INPUT_SIZE * 3];
    char command[INPUT_SIZE * 6];
    char response[RESPONSE_SIZE];
    char payload_path[L_tmpnam];

    if (!read_line("Full name: ", full_name, sizeof(full_name))) {
        return;
    }
    if (!read_line("Username: ", username, sizeof(username))) {
        return;
    }
    if (!read_line("Password: ", password, sizeof(password))) {
        return;
    }

    escaped_name = json_escape_string(full_name);
    escaped_username = json_escape_string(username);
    escaped_password = json_escape_string(password);
    if (!escaped_name || !escaped_username || !escaped_password) {
        printf("\nError: Out of memory.\n");
        goto cleanup;
    }

    snprintf(payload, sizeof(payload), "{\"full_name\":\"%s\",\"username\":\"%s\",\"password\":\"%s\"}", escaped_name, escaped_username, escaped_password);
    if (!write_temp_payload(payload, payload_path, sizeof(payload_path))) {
        printf("\nError: Failed to write payload.\n");
        goto cleanup;
    }

    snprintf(command, sizeof(command),
        "curl -s -X POST \"%s/register\" -H \"Content-Type: application/json\" --data-binary \"@%s\"",
        client->base_url,
        payload_path
    );

    run_curl_request(command, response, sizeof(response));
    print_message(response, "Registration complete.");

cleanup:
    remove(payload_path);
    free(escaped_name);
    free(escaped_username);
    free(escaped_password);
}

static void handle_login(SchoolAPIClient *client)
{
    char username[INPUT_SIZE];
    char password[INPUT_SIZE];
    char *escaped_username = NULL;
    char *escaped_password = NULL;
    char payload[INPUT_SIZE * 2];
    char command[INPUT_SIZE * 5];
    char response[RESPONSE_SIZE];
    char token[TOKEN_SIZE];
    char display_name[NAME_SIZE];
    char payload_path[L_tmpnam];

    if (!read_line("Username: ", username, sizeof(username))) {
        return;
    }
    if (!read_line("Password: ", password, sizeof(password))) {
        return;
    }

    escaped_username = json_escape_string(username);
    escaped_password = json_escape_string(password);
    if (!escaped_username || !escaped_password) {
        printf("\nError: Out of memory.\n");
        goto cleanup;
    }

    snprintf(payload, sizeof(payload), "{\"username\":\"%s\",\"password\":\"%s\"}", escaped_username, escaped_password);
    if (!write_temp_payload(payload, payload_path, sizeof(payload_path))) {
        printf("\nError: Failed to write payload.\n");
        goto cleanup;
    }

    snprintf(command, sizeof(command),
        "curl -s -X POST \"%s/login\" -H \"Content-Type: application/json\" --data-binary \"@%s\"",
        client->base_url,
        payload_path
    );

    run_curl_request(command, response, sizeof(response));
    print_message(response, "Login request sent.");

    if (extract_json_string(response, "token", token, sizeof(token))) {
        safe_copy(client->token, sizeof(client->token), token);
        client->is_logged_in = 1;

        if (extract_json_string(response, "full_name", display_name, sizeof(display_name)) ||
            extract_json_string(response, "username", display_name, sizeof(display_name))) {
            safe_copy(client->display_name, sizeof(client->display_name), display_name);
        } else {
            safe_copy(client->display_name, sizeof(client->display_name), "User");
        }

        printf("Welcome, %s!\n", client->display_name);
    }

cleanup:
    remove(payload_path);
    free(escaped_username);
    free(escaped_password);
}

static void handle_me(SchoolAPIClient *client)
{
    char command[INPUT_SIZE * 4];
    char response[RESPONSE_SIZE];

    snprintf(command, sizeof(command),
        "curl -s \"%s/me\" -H \"Authorization: Bearer %s\"",
        client->base_url,
        client->token
    );

    run_curl_request(command, response, sizeof(response));
    if (!print_account_details(response)) {
        print_message(response, "No account data returned.");
    }
}

static void handle_list_students(SchoolAPIClient *client)
{
    char command[INPUT_SIZE * 4];
    char response[RESPONSE_SIZE];

    snprintf(command, sizeof(command),
        "curl -s \"%s/students\" -H \"Authorization: Bearer %s\"",
        client->base_url,
        client->token
    );

    run_curl_request(command, response, sizeof(response));
    printf("\n");
    if (!print_students(response)) {
        print_message(response, "No students found.");
    }
}

static void handle_add_student(SchoolAPIClient *client)
{
    char name[NAME_SIZE];
    char year_level[INPUT_SIZE];
    char *escaped_name = NULL;
    char *escaped_year = NULL;
    char payload[INPUT_SIZE * 2];
    char command[INPUT_SIZE * 5];
    char response[RESPONSE_SIZE];
    char payload_path[L_tmpnam];

    if (!read_line("Student name: ", name, sizeof(name))) {
        return;
    }
    if (!read_line("Year level: ", year_level, sizeof(year_level))) {
        return;
    }

    escaped_name = json_escape_string(name);
    escaped_year = json_escape_string(year_level);
    if (!escaped_name || !escaped_year) {
        printf("\nError: Out of memory.\n");
        goto cleanup;
    }

    snprintf(payload, sizeof(payload), "{\"name\":\"%s\",\"year_level\":\"%s\"}", escaped_name, escaped_year);
    if (!write_temp_payload(payload, payload_path, sizeof(payload_path))) {
        printf("\nError: Failed to write payload.\n");
        goto cleanup;
    }

    snprintf(command, sizeof(command),
        "curl -s -X POST \"%s/students\" -H \"Content-Type: application/json\" -H \"Authorization: Bearer %s\" --data-binary \"@%s\"",
        client->base_url,
        client->token,
        payload_path
    );

    run_curl_request(command, response, sizeof(response));
    print_message(response, "Student added.");

cleanup:
    remove(payload_path);
    free(escaped_name);
    free(escaped_year);
}

static void handle_add_subject(SchoolAPIClient *client)
{
    char student_id_text[INPUT_SIZE];
    char subject_name[NAME_SIZE];
    char grade_text[INPUT_SIZE];
    char *escaped_subject = NULL;
    char payload[INPUT_SIZE * 3];
    char command[INPUT_SIZE * 6];
    char response[RESPONSE_SIZE];
    char payload_path[L_tmpnam];
    char *end = NULL;
    long student_id;
    double grade_value;

    if (!read_line("Student ID: ", student_id_text, sizeof(student_id_text))) {
        return;
    }
    student_id = strtol(student_id_text, &end, 10);
    if (student_id_text[0] == '\0' || !end || *end != '\0' || student_id <= 0) {
        printf("\nError: Student ID must be a positive number.\n");
        return;
    }

    if (!read_line("Subject name: ", subject_name, sizeof(subject_name))) {
        return;
    }

    if (!read_line("Grade (e.g. 1.75): ", grade_text, sizeof(grade_text))) {
        return;
    }

    grade_value = strtod(grade_text, &end);
    if (grade_text[0] == '\0' || !end || *end != '\0') {
        printf("\nError: Grade must be a number.\n");
        return;
    }

    escaped_subject = json_escape_string(subject_name);
    if (!escaped_subject) {
        printf("\nError: Out of memory.\n");
        return;
    }

    snprintf(payload, sizeof(payload), "{\"subject_name\":\"%s\",\"grade\":%.2f}", escaped_subject, grade_value);
    if (!write_temp_payload(payload, payload_path, sizeof(payload_path))) {
        printf("\nError: Failed to write payload.\n");
        goto cleanup;
    }

    snprintf(command, sizeof(command),
        "curl -s -X POST \"%s/students/%ld/subjects\" -H \"Content-Type: application/json\" -H \"Authorization: Bearer %s\" --data-binary \"@%s\"",
        client->base_url,
        student_id,
        client->token,
        payload_path
    );

    run_curl_request(command, response, sizeof(response));
    print_message(response, "Subject grade added.");

cleanup:
    remove(payload_path);
    free(escaped_subject);
}

static void handle_view_gwa(SchoolAPIClient *client)
{
    char student_id_text[INPUT_SIZE];
    char command[INPUT_SIZE * 4];
    char response[RESPONSE_SIZE];
    char *end = NULL;
    long student_id;

    if (!read_line("Student ID: ", student_id_text, sizeof(student_id_text))) {
        return;
    }

    student_id = strtol(student_id_text, &end, 10);
    if (student_id_text[0] == '\0' || !end || *end != '\0' || student_id <= 0) {
        printf("\nError: Student ID must be a positive number.\n");
        return;
    }

    snprintf(command, sizeof(command),
        "curl -s \"%s/students/%ld/gwa\" -H \"Authorization: Bearer %s\"",
        client->base_url,
        student_id,
        client->token
    );

    run_curl_request(command, response, sizeof(response));
    if (!print_gwa_summary(response)) {
        print_message(response, "No GWA data returned.");
    }
}

static void handle_logout(SchoolAPIClient *client)
{
    char command[INPUT_SIZE * 4];
    char response[RESPONSE_SIZE];

    snprintf(command, sizeof(command),
        "curl -s -X POST \"%s/logout\" -H \"Authorization: Bearer %s\"",
        client->base_url,
        client->token
    );

    run_curl_request(command, response, sizeof(response));
    print_message(response, "Logged out.");

    client->token[0] = '\0';
    client->display_name[0] = '\0';
    client->is_logged_in = 0;
}

static void print_guest_menu(void)
{
    printf(
        "\nChoose an action:\n"
        "[1] Register\n"
        "[2] Login\n"
        "[0] Exit\n"
    );
}

static void print_user_menu(void)
{
    printf(
        "\nChoose an action:\n"
        "[1] Show My Account\n"
        "[2] List Students\n"
        "[3] Add Student\n"
        "[4] Add Subject Grade\n"
        "[5] View Student GWA\n"
        "[6] Logout\n"
        "[0] Exit\n"
    );
}

int main(void)
{
    SchoolAPIClient client;
    char choice[INPUT_SIZE];
    int should_pause;

    init_client(&client);

    for (;;) {
        render_header(&client);
        if (client.is_logged_in) {
            print_user_menu();
        } else {
            print_guest_menu();
        }

        if (!read_line("Enter choice: ", choice, sizeof(choice))) {
            break;
        }

        should_pause = strcmp(choice, "0") != 0;

        clear_terminal();
        print_title("GradeTrack - Simple C Client");
        printf("API Base URL: %s\n", client.base_url);

        if (client.is_logged_in) {
            if (strcmp(choice, "1") == 0) {
                handle_me(&client);
            } else if (strcmp(choice, "2") == 0) {
                handle_list_students(&client);
            } else if (strcmp(choice, "3") == 0) {
                handle_add_student(&client);
            } else if (strcmp(choice, "4") == 0) {
                handle_add_subject(&client);
            } else if (strcmp(choice, "5") == 0) {
                handle_view_gwa(&client);
            } else if (strcmp(choice, "6") == 0) {
                handle_logout(&client);
            } else if (strcmp(choice, "0") == 0) {
                printf("\nExiting C client.\n");
                break;
            } else {
                printf("\nInvalid choice. Please select from the menu.\n");
            }
        } else {
            if (strcmp(choice, "1") == 0) {
                handle_register(&client);
            } else if (strcmp(choice, "2") == 0) {
                handle_login(&client);
            } else if (strcmp(choice, "0") == 0) {
                printf("\nExiting C client.\n");
                break;
            } else {
                printf("\nInvalid choice. Please select from the menu.\n");
            }
        }

        if (should_pause) {
            pause_for_enter();
        }
    }

    return 0;
}
