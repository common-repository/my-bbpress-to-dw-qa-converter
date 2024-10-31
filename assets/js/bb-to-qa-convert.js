jQuery(function($){
	function convert_bbpress( button_action ) {
        $('#bb-qa-convert-result').append('<p id="bb-qa-unique-converting">Converting ....</p>');
        var args = button_action;
        $.ajax({
            type:'POST',
            dataType: "json",
            url: bbToQA.ajax_url,
            data: {
                action : 'ajax_data_convert',
                args: args,
            },
            success: function(response){
            	$('#bb-qa-convert-result').html('');

            	var result = '<p>'+response.category+' Categories converted from Forum</p><p>'+response.tag+' Tags converted from Topic Tag</p><p>'+response.question+' Questions converted from Topic</p><p> All done, <a href="	edit.php?post_type=dwqa-question">have fun.</a> </p>';
            	$('#bb-qa-convert-result').html( result );

            },
            complete: function(xhr, textStatus) {
                if ( xhr.status == 401 ) {
                    return false;
                }
            } 
        });
    }

    $(document).on('click', '#bb-to-qa' ,function(){
        var t = $(this);
        if ( t.is( '#bb-to-qa' )) {
            var action = 'convert';
        }
        convert = new convert_bbpress( action );
    });
});