<?php

class AdminFaviExtra extends AdminTabCore {

    public function display() {
        echo '<script>jQuery("#content").removeClass("nobootstrap").addClass("bootstrap")</script>'; // because i don't know how say presta for using bootstrap
    }
}
