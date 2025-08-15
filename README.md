# GPS-Trip-Analysis-Dashboard
This is a full-stack web application that reads a raw CSV file of GPS data points, processes them into distinct trips, calculates key statistics, and displays the results on an interactive dashboard. It's designed to turn raw location data into a meaningful and easy-to-understand visual summary.
Features

    CSV Processing: Reads and parses GPS data from a local .csv file.

    Data Cleaning: Automatically validates each data point, discarding rows with invalid coordinates or bad timestamps.

    Rejects Logging: All discarded rows are logged to a rejects.log file for transparency and debugging.

    Intelligent Trip Segmentation: Automatically splits the continuous stream of data points into distinct trips based on configurable rules (time gaps > 25 mins or distance jumps > 2 km).

    Statistical Analysis: For each trip, it calculates total distance, duration, average speed, and max speed.

    Interactive Map: Visualizes all trips as colored polylines on an interactive Leaflet.js map. Users can click on a trip to see its stats.

    Modern Dashboard UI: A clean, responsive dashboard built with Tailwind CSS that displays processing summaries and a detailed trip table.

How It Works

The application operates in two main stages:

    Back-End Processing (PHP): When the page is loaded, a PHP script executes on the server. It handles all the heavy lifting:

        Opens and reads the points.csv file.

        Sanitizes and sorts the data points chronologically.

        Runs the core trip segmentation algorithm, implementing the Haversine formula to accurately calculate distances between coordinates.

        Computes all statistics for the identified trips.

        Finally, it embeds the processed data as a clean GeoJSON object into the HTML sent to the browser.

    Front-End Visualization (JavaScript): Once the page loads in the browser, the JavaScript takes over:

        It parses the GeoJSON data provided by the PHP back-end.

        It uses the Leaflet.js library to initialize the map and render the trip LineString geometries.

        It populates the dashboard cards and summary table with the processed stats.

Tech Stack

    Back-End: PHP 8

    Front-End: HTML5, Tailwind CSS, JavaScript (ES6)

    Mapping Library: Leaflet.js

    Fonts: Google Fonts (Inter)

Setup and Usage

    Prerequisites: You need a local web server environment with PHP 8 or higher (e.g., XAMPP, MAMP, WAMP).

    Installation:

        Clone the repository or download the source code.

        Place the project files in your web server's root directory (e.g., htdocs for XAMPP).

    Add Data:

        Place your GPS data file named points.csv in the root of the project directory. The CSV must have the following columns: device_id, lat, lon, timestamp.

    Run the Application:

        Start your local web server.

        Open your web browser and navigate to the project's URL (e.g., http://localhost/your-project-folder).

The application will automatically process the CSV file and display the dashboard.
