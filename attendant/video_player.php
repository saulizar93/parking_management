<?php
include("../includes/header.php");
include("../includes/navbar.php");

$video = basename($_GET['video'] ?? '');
$title = htmlspecialchars($_GET['title'] ?? 'Training Video');
$video_path = "/parking_management/videos/" . $video . ".mp4";
?>

<div class="main-content">

    <div class="container mt-5">

        <div class="card shadow p-4">

            <h2 class="mb-4 text-center">
                <?php echo $title; ?>
            </h2>

            <video class="w-100 mb-4" controls autoplay>
                <source src="<?php echo $video_path; ?>" type="video/mp4">
                Your browser does not support the video tag.
            </video>

            <a href="/parking_management/attendant/training.php"
               class="btn btn-secondary">
                ← Back to Training Videos
            </a>

        </div>

    </div>

</div>

<?php include("../includes/footer.php"); ?>