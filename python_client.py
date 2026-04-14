import json
import os
from getpass import getpass
from urllib import error, request


DEFAULT_BASE_URL = os.getenv("API_BASE_URL", "http://localhost/REST-API/api.php").rstrip("/")


class APIError(Exception):
    def __init__(self, status, message):
        self.status = status
        self.message = message
        super().__init__(f"[HTTP {status}] {message}")


class SchoolAPIClient:
    def __init__(self, base_url):
        self.base_url = base_url.rstrip("/")
        self.token = None
        self.user = None

    def _request(self, method, path, payload=None, use_auth=False):
        url = f"{self.base_url}{path}"
        headers = {"Accept": "application/json"}
        data = None

        if payload is not None:
            headers["Content-Type"] = "application/json"
            data = json.dumps(payload).encode("utf-8")

        if use_auth:
            if not self.token:
                raise APIError(401, "Please log in first.")
            headers["Authorization"] = f"Bearer {self.token}"

        req = request.Request(url, data=data, headers=headers, method=method)

        try:
            with request.urlopen(req) as response:
                raw = response.read().decode("utf-8")
                body = json.loads(raw) if raw else {}
                return response.getcode(), body
        except error.HTTPError as exc:
            raw = exc.read().decode("utf-8")
            try:
                body = json.loads(raw) if raw else {}
            except json.JSONDecodeError:
                body = {}
            raise APIError(exc.code, body.get("message", "Request failed.")) from exc
        except error.URLError as exc:
            raise APIError(0, f"Cannot connect to API: {exc.reason}") from exc

    def register(self, full_name, username, password):
        return self._request(
            "POST",
            "/register",
            {"full_name": full_name, "username": username, "password": password},
        )[1]

    def login(self, username, password):
        body = self._request(
            "POST",
            "/login",
            {"username": username, "password": password},
        )[1]
        self.token = body.get("token")
        self.user = body.get("user")
        return body

    def logout(self):
        body = self._request("POST", "/logout", use_auth=True)[1]
        self.token = None
        self.user = None
        return body

    def me(self):
        return self._request("GET", "/me", use_auth=True)[1]

    def list_students(self):
        return self._request("GET", "/students", use_auth=True)[1]

    def add_student(self, name, year_level):
        return self._request(
            "POST",
            "/students",
            {"name": name, "year_level": year_level},
            use_auth=True,
        )[1]

    def get_student_gwa(self, student_id):
        return self._request("GET", f"/students/{student_id}/gwa", use_auth=True)[1]


def clear_terminal():
    os.system("cls" if os.name == "nt" else "clear")


def print_title(title):
    print("\n" + "=" * 56)
    print(title)
    print("=" * 56)


def print_error(exc):
    print(f"\nError: {exc}")


def print_students(data):
    students = data.get("data", [])
    if not students:
        print("\nNo students found.")
        return

    print()
    for student in students:
        gwa = "N/A" if student.get("gwa") is None else f"{student['gwa']:.2f}"
        print(
            f"ID: {student['id']} | "
            f"Name: {student['name']} | "
            f"Year: {student.get('year_level') or 'N/A'} | "
            f"GWA: {gwa} | "
            f"Remarks: {student.get('remarks', 'N/A')}"
        )


def print_gwa(data):
    info = data.get("data", {})
    if not info:
        print("\nNo GWA data returned.")
        return

    gwa = "N/A" if info.get("gwa") is None else f"{info['gwa']:.4f}"
    print()
    print(f"Student: {info.get('name', 'N/A')}")
    print(f"Year Level: {info.get('year_level') or 'N/A'}")
    print(f"GWA: {gwa}")
    print(f"Remarks: {info.get('remarks', 'N/A')}")
    print(f"Total Subjects: {info.get('total_subjects', 0)}")


def menu():
    print(
        "\nChoose an action:\n"
        "[1] Register\n"
        "[2] Login\n"
        "[3] Show My Account\n"
        "[4] List Students\n"
        "[5] Add Student\n"
        "[6] View Student GWA\n"
        "[7] Logout\n"
        "[0] Exit"
    )
    return input("Enter choice: ").strip()


def render_header(client):
    clear_terminal()
    print_title("School Grading System - Python Client")
    print(f"API Base URL: {client.base_url}")
    if client.user:
        print(f"Logged in as: {client.user.get('full_name', client.user.get('username', 'User'))}")
    else:
        print("Logged in as: Guest")


def main():
    client = SchoolAPIClient(DEFAULT_BASE_URL)

    while True:
        render_header(client)
        choice = menu()
        clear_terminal()
        print_title("School Grading System - Python Client")
        print(f"API Base URL: {client.base_url}")

        try:
            if choice == "1":
                full_name = input("Full name: ").strip()
                username = input("Username: ").strip()
                password = getpass("Password: ")
                res = client.register(full_name, username, password)
                print(f"\n{res.get('message', 'Registration complete.')}")

            elif choice == "2":
                username = input("Username: ").strip()
                password = getpass("Password: ")
                res = client.login(username, password)
                user = res.get("user", {})
                print(f"\n{res.get('message', 'Login successful.')}")
                print(f"Welcome, {user.get('full_name', user.get('username', 'User'))}!")

            elif choice == "3":
                res = client.me()
                user = res.get("data", {})
                print()
                print(f"ID: {user.get('id')}")
                print(f"Name: {user.get('full_name')}")
                print(f"Username: {user.get('username')}")
                print(f"Role: {user.get('role')}")

            elif choice == "4":
                res = client.list_students()
                print_students(res)

            elif choice == "5":
                name = input("Student name: ").strip()
                year_level = input("Year level: ").strip()
                res = client.add_student(name, year_level)
                print(f"\n{res.get('message', 'Student added.')}")

            elif choice == "6":
                student_id = input("Student ID: ").strip()
                if not student_id.isdigit():
                    raise APIError(400, "Student ID must be a number.")
                res = client.get_student_gwa(int(student_id))
                print_gwa(res)

            elif choice == "7":
                res = client.logout()
                print(f"\n{res.get('message', 'Logged out.')}")

            elif choice == "0":
                print("\nExiting Python client.")
                break

            else:
                print("\nInvalid choice. Please select from the menu.")

        except APIError as exc:
            print_error(exc)
        except KeyboardInterrupt:
            print("\n\nExiting Python client.")
            break


if __name__ == "__main__":
    main()
