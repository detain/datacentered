<!DOCTYPE html>
<title>Stats</title>
<meta charset="utf8">
<link rel="stylesheet" href="css/vmstat.css">
<script type="text/javascript" src="js/sugar-1.4.1.min.js"></script>
<script type="text/javascript" src="//my.interserver.net/bower_components/jquery-2.1.x/dist/jquery.min.js"></script>
<script type="text/javascript" src="js/reconnecting-websocket.js"></script>
<script type="text/javascript" src="js/smoothie.js"></script>
<script type="text/javascript" src="js/chroma.min.js"></script>
<script type="text/javascript" src="js/vmstat.js"></script>

<main id="charts">
  <section class="chart template">
	<h2 class="title"></h2>
	<canvas width="600" height="80"></canvas>
	<ul class="stats">
		<li class="stat template">
			<span class="stat-name"></span>
			<span class="stat-value"></span>
		</li>
	</ul>
  </section>
</main>
