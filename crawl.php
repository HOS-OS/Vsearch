<?php
set_time_limit(300);

function getDomain($url) {
    $urlParts = parse_url($url);
    return $urlParts['scheme'] . '://' . $urlParts['host'];
}

function isFullWord($url) {
    $path = parse_url($url, PHP_URL_PATH);
    $components = explode('/', trim($path, '/'));

    foreach ($components as $component) {
        if (!ctype_alpha($component)) {
            return false;
        }
    }

    return true;
}

function urlExistsInDatabase($db, $url) {
    $stmt = $db->prepare("SELECT id FROM sites WHERE url = ?");
    $stmt->bind_param("s", $url);
    $stmt->execute();
    $stmt->store_result();
    $count = $stmt->num_rows;
    $stmt->close();
    return $count > 0;
}

function getShortDescription($inputText) {
    // Remove non-text characters and decode HTML entities
    $cleanText = html_entity_decode(preg_replace('/[^a-zA-Z\s]/', '', $inputText));

    // Remove extra whitespace and newlines
    $cleanText = preg_replace('/\s+/', ' ', $cleanText);

    // Limit the description length
    $maxDescriptionLength = 500; // You can adjust this length
    $description = mb_substr($cleanText, 0, $maxDescriptionLength);

    return $description;
}

function crawlWebsite($url, $searchText) {
    $visited = array();
    $toVisit = array($url);
    $domain = getDomain($url);

    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPass = '';
    $dbName = 'vsearch';

    $db = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
    if ($db->connect_error) {
        die("Connection failed: " . $db->connect_error);
    }

    while (!empty($toVisit)) {
        $currentUrl = array_shift($toVisit);

        if (!in_array($currentUrl, $visited) && strpos($currentUrl, '#') === false) {
            $visited[] = $currentUrl;

            if (strpos($currentUrl, $searchText) === false) {
                continue; // Skip this URL if it doesn't contain the search text
            }

            $ch = curl_init($currentUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $html = curl_exec($ch);

            if ($html === false) {
                echo "Error fetching URL: " . curl_error($ch) . "<br>";
                continue;
            }

            $dom = new DOMDocument();
            @$dom->loadHTML($html);

            $title = $dom->getElementsByTagName('title')->item(0)->textContent;

            $stmt = $db->prepare("SELECT id FROM sites WHERE title = ?");
            $stmt->bind_param("s", $title);
            $stmt->execute();
            $stmt->store_result();
            $count = $stmt->num_rows;
            $stmt->close();

            if ($count > 0) {
                continue; // Skip insertion if a site with the same title already exists
            }

            $metaTags = $dom->getElementsByTagName('meta');
            $description = '';
            $keywords = '';

            foreach ($metaTags as $tag) {
                if ($tag->getAttribute('name') == 'description') {
                    $description = $tag->getAttribute('content');
                }
                if ($tag->getAttribute('name') == 'keywords') {
                    $keywords = $tag->getAttribute('content');
                }
            }

            if (empty($description)) {
                $description = getShortDescription($html);
            }

            if (!urlExistsInDatabase($db, $currentUrl)) {
                $stmt = $db->prepare("INSERT INTO sites (url, title, description, keywords) VALUES (?, ?, ?, ?)");
                $stmt->bind_param("ssss", $currentUrl, $title, $description, $keywords);
                $stmt->execute();
                $stmt->close();
            }

            $links = $dom->getElementsByTagName('a');
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                $absoluteUrl = (strpos($href, 'http') === 0) ? $href : rtrim($domain, '/') . '/' . ltrim($href, '/');

                if (strpos($absoluteUrl, $domain) !== false && !in_array($absoluteUrl, $visited) && !in_array($absoluteUrl, $toVisit)) {
                    $toVisit[] = $absoluteUrl;

                    if (isFullWord($absoluteUrl)) {
                        echo '<span style="color: blue;">' . $absoluteUrl . '</span><br>';
                    } else {
                        echo $absoluteUrl . '<br>';
                    }
                }
            }

            curl_close($ch);
        }
    }

    $db->close();
}

if (isset($_POST['submit'])) {
    $startUrl = $_POST['website'];
    $searchText = htmlspecialchars($startUrl); // Use the entered text as search text
    crawlWebsite($startUrl, $searchText);
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Web Crawler</title>
</head>
<body>
    <form method="post">
        <label for="website">Enter website URL:</label>
        <input type="text" name="website" id="website" required>
        <button type="submit" name="submit">Crawl</button>
    </form>
</body>
</html>
