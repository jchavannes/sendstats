<style type="text/css">
    body {
        background: #000;
        margin: 0;
        padding: 0;
    }
</style>
<img id="graph" src="" />

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
        var url = "http://graphite.jchavannes.com/render/?";
        url += "width=" + width + "&height=" + height + "&";
        url += "_salt=" + salt + "&";
        url += "lineMode=staircase&areaMode=stacked&";
        url += "from=-2hours&";
        url += 'target=aliasSub(summarize(stats.apache.requests.*,"1minute")%2C".*requests.(%5B%5E%2C%5D%2B).*"%2C"%5C1")&';
        url += "tz=US/Pacific&";
        url += "title=Page Views Per Minute&";
        url += "xFormat=%25I%3A%25M%20%25p&";
        document.getElementById("graph").src = url;
        graphTimeout = setTimeout(resetGraph, 2000);
    }
    getSizes();
    window.onresize = function() {getSizes();};
</script>
