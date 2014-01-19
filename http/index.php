<style type="text/css">
    body {
        background: #000;
        margin: 0;
        padding: 0;
    }
</style>
<img id="graph" src="" />

<!--
http://graphite.jchavannes.com/graph/?group=main&from=-2hours&to=-0hours&summary=1minute
http://graphite.jchavannes.com/graph/?sites=jchavannes,wantlistapp
-->

<script>
    var width = 0;
    var height = 0;
    function getSizes() {
        width = window.innerWidth;
        height = window.innerHeight;
        if (width == 0 || height == 0) {
            alert('Error getting window measurements');
        }
        resetGraph();
    }
    var graphTimeout;
    function resetGraph() {
        clearTimeout(graphTimeout);
        var salt = Math.random() * 100000;
        var sites = "<?
            $default = "*";
            $groups = array(
                "main" => "{jchavannes,wantlistapp,theenglishgarden,d_jchavannes,svndeploy,jasonc_me,jennifergross,jford_co,noobsonly,repo_svndeploy,zootheory}"
            );
            if (isset($_GET['sites'])) {
                echo "{" . $_GET['sites'] . "}";
            }
            else if (isset($_GET['group']) && isset($groups[$_GET['group']])) {
                echo $groups[$_GET['group']];
            }
            else {
                echo $default;
            }
        ?>";
        var url = "http://graphite.jchavannes.com/render/?";
        url += "width=" + width + "&height=" + height + "&";
        url += "_salt=" + salt + "&";
        url += "lineMode=staircase&areaMode=stacked&";
        url += "from=<?
            $default = "-2hours";
            if (isset($_GET['from'])) {
                echo $_GET['from'];
            }
            else {
                echo $default;
            }
        ?>&";
        url += "until=<?
            $default = "-0hours";
            if (isset($_GET['until'])) {
                echo $_GET['until'];
            }
            else {
                echo $default;
            }
        ?>&";
        <? $summary = isset($_GET['summary']) ? $_GET['summary'] : "1minute"; ?>
        url += 'target=aliasSub(summarize(stats.jchavannes.requests.' + sites + ',"<?=$summary?>")%2C".*requests.(%5B%5E%2C%5D%2B).*"%2C"%5C1")&';
        url += "tz=US/Pacific&";
        url += "title=Requests Per Minute&";
        url += "xFormat=%25I%3A%25M%20%25p&";
        url += "hideLegend=false&";
        document.getElementById("graph").src = url;
        graphTimeout = setTimeout(resetGraph, 2000);
    }
    getSizes();
    window.onresize = function() {getSizes();};
</script>
