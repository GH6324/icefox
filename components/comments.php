<div class="border border-[#07c160] rounded-lg p-2 bg-white">
    <div data-action="<?php $this->commentUrl() ?>" class="url-<?php $this->respondId(); ?>">
        <label>
            <input class="w-full h-full rounded-lg outline-none resize-none text-<?php $this->respondId(); ?>" name="text" />
        </label>
        <div class="flex justify-between items-center">
            <div>
                1
            </div>
            <div class="flex flex-row items-center justify-end">
                <span>
                    <svg xmlns="http://www.w3.org/2000/svg" version="1.1" baseProfile="full"
                         width="24" height="24" viewBox="0 0 512 512">
                      <circle cx="256" cy="256" r="208" stroke-miterlimit="10" stroke-width="32" fill="none" stroke="#07c160"/>
                      <circle cx="184" cy="232" r="24" fill="#07c160" stroke="none"/>
                      <circle cx="328" cy="232" r="24" fill="#07c160" stroke="none"/>
                      <path d="M256.05 384c-45.42 0-83.62-29.53-95.71-69.83a8 8 0 017.82-10.17h175.69a8 8 0 017.82 10.17c-11.99 40.3-50.2 69.83-95.62 69.83z" fill="#07c160" stroke="none"/>
                    </svg>
                </span>
                <button class="bg-[#07c160] text-white pl-3 pr-3 pt-1 pb-1 ml-2 rounded-sm comment-submit" type="button" data-id="<?php $this->respondId(); ?>">提交</button>
            </div>
        </div>
    </div>
</div>