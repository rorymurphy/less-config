
//jQuery(document).ready(function($){
//    var settings = _.chain(wp_bootstrap_config_settings).values().map(function(val){ return _.values(val); }).flatten();
//    $('#wp-config-settings input[type="text"]').each(function(idx, elem){
//        var $el = $(elem);
//        var itemName = /^settings\[([^\]]+)]$/.exec($el.attr('name'));
//        itemName = itemName[1];
//        var item = settings.filter(function(itm){ return itm.name === itemName; }).value()[0];
//        $(elem).typeahead({
//            source: function(query, process){
//                var result = settings.filter(function(itm){
//                    return itm.type === item.type
//                            && itm.order < item.order
//                            && itm.name.indexOf(query) >= 0
//                }).pluck('name').value();
//                return result;
//            }
//        });
//    });
//    
//    $('[data-toggle="tooltip"]').tooltip({html: false, container: 'div.wrap'});
//});

(function($){
    $(document).on('shown', '.collapse', function(evt, ui){
       var tgt = $(evt.target);
       if(!tgt.is('.collapse')){return;}
       var slug = tgt.attr('id').replace(' ', '-').toLowerCase();
       var toggles = $('a[data-toggle="collapse"][href="#' + slug + '"]');
       toggles.addClass('in');
    });
    $(document).on('hidden', '.collapse', function(evt, ui){
       var tgt = $(evt.target);
       if(!tgt.is('.collapse')){return;}
       var slug = tgt.attr('id').replace(' ', '-').toLowerCase();
       var toggles = $('a[data-toggle="collapse"][href="#' + slug + '"]');
       toggles.removeClass('in');
    });
        
    var form = $('form#less-config');
    form.on('submit', function(evt, ui){
        if(evt.isDefaultPrevented()){return;}

        var data = {};
 
        form.find('input[type="text"], input[type="hidden"], textarea, select, input[type="checkbox"]:checked').each(function(idx){
            data[$(this).attr('name')] = $(this).val();
        });
        $.ajax({
            type: 'POST',
            url: form.attr('action'),
            data: data,
            dataType: 'json',
            success: function(data){
                var results = {};
                var keys = _.keys(data);
                var loop = function(){
                    if(keys.length > 0){
                        var k = keys.shift();
                        less.compile(data[k], function(styles){
                            results[k] = styles;
                            loop();
                        });
                    }else{
                        $.ajax({
                           type: 'POST',
                           url: form.attr('data-save-url'),
                           data: {
                               files: results
                           },
                           dataType: 'json',
                           success: function(data){
                               console.log("Styles updated");
                           }
                       });                       
                    }
                }
                loop();
            },
            error: function(){
                alert('An error occurred while processing your acceptance, please try again in a moment');
            }
        });
        evt.preventDefault();
    });
    

}(jQuery));