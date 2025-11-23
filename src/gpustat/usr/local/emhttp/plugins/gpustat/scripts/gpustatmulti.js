/*
  MIT License

  Copyright (c) 2020-2022 b3rs3rk

  Permission is hereby granted, free of charge, to any person obtaining a copy
  of this software and associated documentation files (the "Software"), to deal
  in the Software without restriction, including without limitation the rights
  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
  copies of the Software, and to permit persons to whom the Software is
  furnished to do so, subject to the following conditions:

  The above copyright notice and this permission notice shall be included in all
  copies or substantial portions of the Software.

  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
  SOFTWARE.
*/


function toggleVFIO(vfio,panel,vfiovm) {
    if (vfio) {
      $('.vfio_inuse'+panel).show();
      $('.vfio_notinuse'+panel).hide();
      if (vfiovm != false) {
        var vfiovmsplit = vfiovm.split(","); 
        $('.vfio_status'+panel).text(_("GPU inuse in a VM: ")+vfiovmsplit[0]);
        $('.vmicon'+panel).attr('src', vfiovmsplit[1]);
        $('.vmicon'+panel).show();
      } else {
        $('.vfio_status'+panel).text(_("GPU not available bound to VFIO"));
        $('.vmicon'+panel).hide();
      }
    } else {
      $('.vfio_inuse'+panel).hide();
      $('.vfio_notinuse'+panel).show();
    }
  }

const gpustat_statusm = (input) => {
    $.getJSON('/plugins/gpustat/gpustatusmulti.php?gpus='+JSON.stringify(input), (data2) => {
        if (data2) {
        $.each(data2, function (key2, data) {
            panel = data["panel"] ;
            if (!data['vfio']) {
                toggleVFIO(false,panel) ;
                switch (data["vendor"]) {
                    case 'NVIDIA':
                        // Nvidia Slider Bars
                        $('.gpu-memclockbar'+panel).removeAttr('style').css('width', data["memclock"] / data["memclockmax"] * 100 + "%");
                        $('.gpu-gpuclockbar'+panel ).removeAttr('style').css('width', data["clock"] / data["clockmax"] * 100 + "%");
                        $('.gpu-powerbar'+panel ).removeAttr('style').css('width', parseInt(data["power"].replace("W","") / data["powermax"] * 100) + "%");
                        $('.gpu-rxutilbar'+panel).removeAttr('style').css('width', parseInt(data["rxutil"] / data["pciemax"] * 100) + "%");
                        $('.gpu-txutilbar'+panel).removeAttr('style').css('width', parseInt(data["txutil"] / data["pciemax"] * 100) + "%");

                        let nvidiabars = ['util', 'memutil', 'encutil', 'decutil', 'fan'];
                        nvidiabars.forEach(function (metric) {
                            $('.gpu-'+metric+'bar'+panel).removeAttr('style').css('width', data[metric]);
                        });
                        break;
                    case 'Intel':
                        // Intel Slider Bars
                        $('.gpu-fanbar'+panel).removeAttr('style').css('width', parseInt(data["fan"] / data["fanmax"] * 100) + "%");
                        let intelbars = ['3drender', 'blitter', 'video', 'videnh', 'powerutil', 'compute'];
                        intelbars.forEach(function (metric) {
                            $('.gpu-'+metric+'bar'+panel).removeAttr('style').css('width', data[metric]);
                        });
                        break;
                    case 'AMD':
                        $('.gpu-powerbar'+panel).removeAttr('style').css('width', parseInt(data["power"].replace("W","") / data["powermax"] * 100) + "%");
                        $('.gpu-fanbar'+panel).removeAttr('style').css('width', parseInt(data["fan"] / data["fanmax"] * 100) + "%");
                        let amdbars = [
                            'util', 'event', 'vertex',
                            'texture', 'shaderexp', 'sequencer',
                            'shaderinter', 'scancon', 'primassem',
                            'depthblk', 'colorblk', 'memutil',
                            'gfxtrans', 'memclockutil', 'clockutil'
                        ];
                        amdbars.forEach(function (metric) {
                            $('.gpu-'+metric+'bar'+panel).removeAttr('style').css('width', data[metric]);
                        });
                        break;
                }

                if (data["appssupp"]) {
                    data["appssupp"].forEach(function (app) {
                        if (data[app + "using"]) {
                            $('.gpu-img-span-'+app+panel).css('display', "inline");
                            $('#gpu-'+app+panel).attr('title', "Name:" + app.charAt(0).toUpperCase() + app.slice(1) + " Count: " + data[app+"count"] + " Memory: " + data[app+"mem"] + "MB");
                        } else {
                            $('.gpu-img-span-'+app+panel).css('display', "none");
                            $('#gpu-'+app+panel).attr('title', "");
                        }
                    });
                }

                if (data["active_apps"]) {
                    const appList = [];
                    $('.gpu-active-apps' + panel + ' .gpu-img-span').each(function () {
                        appList.push($(this).data('name'));
                    });
                    const active_apps = [];
                    data["active_apps"].forEach(function (app) {
                        active_apps.push(app.name);
                        if (appList.includes(app.name)) {
                            const title = 'App: ' + app.title + ' - Count: ' + app.count + ' - Memory: ' + app.mem + 'MB';
                            $('.gpu-active-apps' + panel + ' td span[data-name="' + app.name + '"] img').attr('title', title);
                                } else {
                                    const title = 'App: ' + app.title + ' - Count: ' + app.count + ' - Memory: ' + app.mem + 'MB';
                                    const img = $('<img class="gpu-image" src="' + app.icon + '" title="' + title + '">');
                                    const span = $('<span class="gpu-img-span" data-name="' + app.name + '"></span>');
                                    span.append(img);
                                    $('.gpu-active-apps' + panel + ' td').append(span);
                                }
                            });
                            $('.gpu-active-apps' + panel + ' td span.gpu-img-span').each(function () {
                                if (!active_apps.includes($(this).data('name')))
                                    $(this).remove();
                            });
                        }
                        
                $.each(data, function (key, data) {
                    if (key == "error") {   
                        toggleVFIO(true,panel,false) ;
                        var error_text = data[0]["message"] ;
                        $('.vfio_status'+panel).text(_(error_text));
                    }
                    $('.gpu-'+key+panel).html(data);
                    })


            } else {
                toggleVFIO(true,panel,data["vfiovm"]) ;
                $('.gpu-name'+panel).html(data["name"]);
                $('.gpu-vendor'+panel).html(data["vendor"]);
                $('.gpu-driver'+panel).html(data["driver"]);
                $('.gpu-pciegen'+panel).html(data["pciegen"]);
                $('.gpu-pciegenmax'+panel).html(data["pciegenmax"]);
                $('.gpu-pciewidth'+panel).html(data["pciewidth"]);
                $('.gpu-pciewidthmax'+panel).html(data["pciewidthmax"]);
            }
            if (data["igpu"] == "1") {
                $('.nopcie'+panel).hide();
            } else {
                $('.nopcie'+panel).show();
            }
            // Prefer file value if both cookie and file exist
            var hidden = (typeof cookie !== 'undefined' && cookie.hidden_content) 
                ? cookie.hidden_content 
                : $.cookie('hidden_content');
            hidden = hidden ? hidden.split(';') : [];
            
            var panelId = "#tblGPUDash" + panel;
            var panelHash = $(panelId).attr('title').md5();
            
            if (hidden.includes(panelHash)) {
                $(panelId).mixedView(0);
            }
   
            })
         }
   

    });
};


/*
TODO: Not currently used due to issue with default reset actually working
function resetDATA(form) {
    form.VENDOR.value = "nvidia";
    form.TEMPFORMAT.value = "C";
    form.GPUID.value = "0";
    form.DISPCLOCKS.value = "1";
    form.DISPENCDEC.value = "1";
    form.DISPTEMP.value = "1";
    form.DISPFAN.value = "1";
    form.DISPPCIUTIL.value = "1";
    form.DISPPWRDRAW.value = "1";
    form.DISPPWRSTATE.value = "1";
    form.DISPTHROTTLE.value = "1";
    form.DISPSESSIONS.value = "1";
    form.UIREFRESH.value = "1";
    form.UIREFRESHINT.value = "1000";
    form.DISPMEMUTIL.value = "1";
    form.DISP3DRENDER.value = "1";
    form.DISPBLITTER.value = "1";
    form.DISPVIDEO.value = "1";
    form.DISPVIDENH.value = "1";
    form.DISPINTERRUPT.value = "1";
}
*/
