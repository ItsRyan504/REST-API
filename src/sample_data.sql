USE gradetrack;

-- Import this into a fresh or empty gradetrack database.
-- Demo login
-- username: teacher_demo
-- password: teacher123
INSERT INTO users (id, full_name, username, password, role, created_at) VALUES
(1, 'Demo Teacher', 'teacher_demo', '$2y$10$7PG5qO0msPZd4giIzYtdBOG3cFgASw9W.7Y3iuFbpR2m/9wm.SGkO', 'teacher', UNIX_TIMESTAMP('2026-04-13 08:00:00'));

INSERT INTO students (id, name, year_level, created_by, created_at) VALUES
(1, 'Juan Dela Cruz', '1st Year', 1, UNIX_TIMESTAMP('2026-04-13 08:10:00')),
(2, 'Maria Santos', '2nd Year', 1, UNIX_TIMESTAMP('2026-04-13 08:11:00')),
(3, 'Jose Reyes', '3rd Year', 1, UNIX_TIMESTAMP('2026-04-13 08:12:00')),
(4, 'Ana Villanueva', '4th Year', 1, UNIX_TIMESTAMP('2026-04-13 08:13:00')),
(5, 'Carlo Mendoza', '1st Year', 1, UNIX_TIMESTAMP('2026-04-13 08:14:00')),
(6, 'Bea Navarro', '2nd Year', 1, UNIX_TIMESTAMP('2026-04-13 08:15:00')),
(7, 'Miguel Torres', '3rd Year', 1, UNIX_TIMESTAMP('2026-04-13 08:16:00')),
(8, 'Sofia Garcia', '4th Year', 1, UNIX_TIMESTAMP('2026-04-13 08:17:00')),
(9, 'Paolo Ramirez', '2nd Year', 1, UNIX_TIMESTAMP('2026-04-13 08:18:00')),
(10, 'Lara Bautista', '1st Year', 1, UNIX_TIMESTAMP('2026-04-13 08:19:00'));

INSERT INTO subjects (student_id, name, grade, label) VALUES
(1, 'Mathematics', 1.25, 'Superior'),
(1, 'English', 1.50, 'Superior'),
(1, 'Science', 1.75, 'Very Good'),
(2, 'Mathematics', 2.00, 'Very Good'),
(2, 'History', 2.25, 'Good'),
(2, 'Programming', 1.50, 'Superior'),
(3, 'Database Systems', 1.25, 'Superior'),
(3, 'Networking', 1.75, 'Very Good'),
(3, 'Web Development', 1.50, 'Superior'),
(4, 'Research', 1.00, 'Excellent'),
(4, 'Capstone', 1.25, 'Superior'),
(4, 'Technical Writing', 1.75, 'Very Good'),
(5, 'Biology', 2.50, 'Satisfactory'),
(5, 'Chemistry', 2.25, 'Good'),
(5, 'Physics', 2.00, 'Very Good'),
(6, 'Accounting', 1.75, 'Very Good'),
(6, 'Economics', 2.00, 'Very Good'),
(6, 'Business Math', 1.50, 'Superior'),
(7, 'Algorithms', 1.25, 'Superior'),
(7, 'Data Structures', 1.50, 'Superior'),
(7, 'Operating Systems', 1.75, 'Very Good'),
(8, 'Humanities', 2.25, 'Good'),
(8, 'Communication', 1.75, 'Very Good'),
(8, 'Statistics', 2.00, 'Very Good'),
(9, 'Literature', 2.75, 'Satisfactory'),
(9, 'Philosophy', 2.50, 'Satisfactory'),
(9, 'Sociology', 2.25, 'Good'),
(10, 'Introduction to IT', 1.50, 'Superior'),
(10, 'Computer Fundamentals', 1.25, 'Superior'),
(10, 'Discrete Math', 1.75, 'Very Good');
