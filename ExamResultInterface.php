<?php
// Connect to the database
$servername = "127.0.0.1";
$username = "root"; // Default XAMPP username
$password = ""; // Default XAMPP password
$dbname = "apaagps_db"; // Replace with your database name

$conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);

// Check the connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Fetch data from the events table
$sql = "SELECT id, StudentID, SName, Edescript, EventStartDate, EventEndDate, EventStartTime, EventEndTime,HostOrg, EventOrg, Organizeremail, Organizerphoneno, Attsize, AdditionalComments, Created_by FROM event";
$stmt = $conn->prepare($sql);
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Student Result</title>
    <style>
        /* Basic styling */
        *{ box-sizing: border-box; margin: 0;   padding: 0; }
        table { display: flex; justify-content: left; margin: 2em 0em; width: 10%; border-collapse: collapse; }
        th, td { padding: 8px 12px; border: 1px solid black; text-align: center; }
        th { color: white; background-color: rgba(102, 51, 0, 0.75); }
        .header { display: flex; justify-content: center; align-items: center; padding: 1em 0em; background-color: rgba(102, 51, 0, 0.75); color: rgb(255, 255, 255);}
        h1 { font-family: "Inter", sans-serif;font-optical-sizing: auto; font-weight:700; font-style: normal;}
        td { background-color: rgba(255, 207, 140, 1)}
        td:hover {
            background-color: white;
        }
    </style>
</head>
<body>
    <h1 class="header" style="text-align: center;">Event List</h1>
    <table>
        <tr>
            <th>Event ID</th>
            <th>Event No</th>
            <th>Event Name</th>
            <th>Event Description</th>
            <th>Event Start Date</th>
            <th>Event End Date</th>
            <th>Event Start Time</th>
            <th>Event End Time</th>
            <th>Host Organization</th>
            <th>Event Organizer</th>
            <th>Organizers Email</th>
            <th>Organizers Phone Number</th>
            <th>Attendee Size</th>
            <th>Additional Comments</th>
            <th>Created By</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= htmlspecialchars($event['id']) ?></td>
                <td><?= htmlspecialchars($event['Eventno']) ?></td>
                <td><?= htmlspecialchars($event['EventName']) ?></td>
                <td><?= htmlspecialchars($event['Edescript']) ?></td>
                <td><?= htmlspecialchars($event['EventStartDate']) ?></td>
                <td><?= htmlspecialchars($event['EventEndDate']) ?></td>
                <td><?= htmlspecialchars($event['EventStartTime']) ?></td>
                <td><?= htmlspecialchars($event['EventEndTime']) ?></td>
                <td><?= htmlspecialchars($event['HostOrg']) ?></td>
                <td><?= htmlspecialchars($event['EventOrg']) ?></td>
                <td><?= htmlspecialchars($event['Organizeremail']) ?></td>
                <td><?= htmlspecialchars($event['Organizerphoneno']) ?></td>
                <td><?= htmlspecialchars($event['Attsize']) ?></td>
                <td><?= htmlspecialchars($event['AdditionalComments']) ?></td>
                <td><?= htmlspecialchars($event['Created_by']) ?></td>
                <td>
                    <a href="event_interface.php?id=<?= htmlspecialchars($event['id']) ?>">Add</a> |
                    <a href="UpdateEvent.php?id=<?= htmlspecialchars($event['id']) ?>">Update</a> |
                    <a href="DeleteEvent.php?id=<?= htmlspecialchars($event['id']) ?>" onclick="return confirm('Are you sure you want to delete this event?');">Delete</a>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>

<?php
$conn->close();
?>