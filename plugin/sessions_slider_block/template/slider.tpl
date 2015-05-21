{% if sessions_slider_block.sessions|length > 0 %}
<link href="{{ _p.web_plugin }}sessions_slider_block/resources/owl-carousel/owl.carousel.css" rel="stylesheet">
<link href="{{ _p.web_plugin }}sessions_slider_block/resources/owl-carousel/owl.theme.css" rel="stylesheet">
<link href="{{ _p.web_plugin }}sessions_slider_block/resources/style.css" rel="stylesheet">
<div class="row">
    <div class="col-md-12">
        <h3><a href="#">{{ "RecommendedCourses"|get_lang }}</a></h3>
        <div id="top-session">
            {% for session in sessions_slider_block.sessions %}
            <div class="item">
                <div class="thumbnail">
                    <a href="{{ session.url_in_slider }}" target="_blank">
                        <img src="{{ _p.web_upload ~ session.image_in_slider }}" alt="{{ session.name }}">
                    </a>
                    <div class="caption">
                        <h3>
                            <a href="{{ session.url_in_slider }}" target="_blank">{{ session.name }}</a>
                        </h3>
                    </div>
                </div>
            </div>
            {% endfor %}
        </div>
        <script src="{{ _p.web_plugin }}sessions_slider_block/resources/owl-carousel/owl.carousel.min.js"></script>
        <script type="text/javascript">
            $(document).ready(function() {
                $("#top-session").owlCarousel({
                    autoPlay: 3000, //Set AutoPlay to 3 seconds
                    items : 3,
                    itemsDesktop : [1199,3],
                    itemsDesktopSmall : [979,3],
                    navigation : true,
                    pagination : false
                });
            });
        </script>
    </div>
</div>
{% endif %}
